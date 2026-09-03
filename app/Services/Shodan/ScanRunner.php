<?php

namespace App\Services\Shodan;

use App\Exceptions\ScrapingException;
use App\Models\Host;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\ScanStep;
use App\Services\Shodan\Parsers\FacetPageParser;
use App\Services\Shodan\Parsers\SearchPageParser;
use Illuminate\Support\Facades\DB;

/**
 * Enumeration by filter splitting.
 *
 * THE PROBLEM. Shodan always announces a query's total result count, but only
 * lets a visitor read the first two pages, i.e. 20 results. Past that it asks
 * for a subscription. A query with 39 results therefore leaves 19 out of reach.
 *
 * THE LEVER. The "Top ports", "Top organizations" rankings shown beside the
 * results ARE computed over the whole result set, and each one is a link to the
 * same query plus one filter. They describe a partition of the results, for
 * free.
 *
 * THE ALGORITHM. While a query exceeds the ceiling, replace it with its slices
 * (port:80, port:8080, port:443...). A slice under the ceiling is harvested; a
 * slice still too large is split again on the next facet. Everything is glued
 * back together, de-duplicated on (IP, port).
 *
 *     country:"PL" Date: ...09:13:03 GMT   39 results  -> too many -> split
 *       + port:80                          22 results  -> too many -> split
 *           + org:"Multinet24 Sp.zoo"       9 results  -> harvest
 *           + org:"Oxylion Sp. z o.o."      7 results  -> harvest
 *       + port:8080                         4 results  -> harvest
 *       + port:443                          3 results  -> harvest
 *
 * WHAT WE STILL MISS. Shodan caps any facet page at 100 values (measured
 * 2026-09-03), so a ranking never lists every value. The residual probe below
 * closes most of that gap by naming the blind spot in the negative. Coverage is
 * therefore measured and displayed, never assumed.
 */
class ScanRunner
{
    /** Requests already sent during this run. */
    private int $requests = 0;

    /** Position of the next traced step. */
    private int $position = 0;

    /** Already harvested "ip:port" keys, used for de-duplication. */
    private array $seen = [];

    public function __construct(
        private readonly ShodanClient $client,
        private readonly SearchPageParser $searchParser,
        private readonly FacetPageParser $facetParser,
    ) {}

    /**
     * Runs the scan and returns the same model, refreshed.
     *
     * Never fails on a network error: an interrupted scan keeps what it
     * harvested and says so through its status.
     */
    public function run(Scan $scan): Scan
    {
        $this->requests = 0;
        $this->position = 0;
        $this->seen = [];

        $budget = max(1, $scan->max_requests);
        $totalReported = 0;

        foreach ($this->rootQueries($scan) as $root) {
            if ($this->requests >= $budget) {
                break;
            }

            $totalReported += $this->explore($scan, $root, usedFacets: []);
        }

        $scan->forceFill([
            'total_reported' => $totalReported,
            'unique_hosts' => $scan->results()->count(),
            'requests_used' => $this->requests,
            'status' => $this->requests >= $budget
                ? Scan::STATUS_BUDGET_EXHAUSTED
                : Scan::STATUS_COMPLETED,
            'finished_at' => now(),
        ])->save();

        return $scan->refresh();
    }

    /**
     * The starting queries.
     *
     * A pinned second gives a single root. Leaving the second empty makes the
     * second itself the first splitting dimension: the 60 seconds of a minute
     * form a perfect partition, with no overlap and no blind spot. It is the
     * cleanest split available here, and also the most expensive -- one request
     * per second.
     *
     * @return list<ShodanQuery>
     */
    private function rootQueries(Scan $scan): array
    {
        // Term scan: a single root, the banner search. Facet splitting takes
        // over from there exactly as it does for a timestamped scan.
        if (filled($scan->base_term)) {
            return [ShodanQuery::forCountryTerm($scan->country_code, $scan->base_term)];
        }

        $minute = $scan->observed_on->copy()->setTime(
            $scan->observed_hour,
            $scan->observed_minute,
        );

        if ($scan->observed_second !== null) {
            return [ShodanQuery::forCountryAt(
                $scan->country_code,
                $minute->copy()->setSecond($scan->observed_second),
            )];
        }

        return array_map(
            fn (int $second) => ShodanQuery::forCountryAt(
                $scan->country_code,
                $minute->copy()->setSecond($second),
            ),
            range(0, 59),
        );
    }

    /**
     * Handles one query: harvest it if it fits under the ceiling, split it
     * otherwise. Returns the total Shodan announced for that query.
     *
     * @param  list<string>  $usedFacets  facets already consumed in this branch
     */
    private function explore(Scan $scan, ShodanQuery $query, array $usedFacets, ?string $appliedFilter = null): int
    {
        if (! $this->hasBudget($scan)) {
            $this->trace($scan, $query, $appliedFilter, 0, 0, 0, ScanStep::DECISION_BUDGET_EXHAUSTED);

            return 0;
        }

        try {
            $page = $this->fetchSearchPage($scan, $query);
        } catch (ScrapingException $e) {
            $this->trace($scan, $query, $appliedFilter, 0, 0, 0, ScanStep::DECISION_FAILED, $e->getMessage());

            return 0;
        }

        $total = $page['total_results'];
        $ceiling = $this->ceiling();

        if ($total <= $ceiling) {
            return $this->harvest($scan, $query, $appliedFilter, $page);
        }

        // The residual is split on the same facet round after round: without a
        // bound, a query that stopped shrinking would keep the descent running
        // until the budget ran dry.
        if ($query->depth() >= (int) config('geoscan.enumeration.max_depth', 24)) {
            return $this->harvest(
                $scan, $query, $appliedFilter, $page,
                decision: ScanStep::DECISION_ABANDONED,
                note: 'profondeur maximale atteinte : seuls les résultats visibles sont pris',
            );
        }

        return $this->split($scan, $query, $appliedFilter, $page, $usedFacets);
    }

    /**
     * The query fits under the ceiling: collect its results, page 2 included
     * when Shodan offers one.
     *
     * @param  array<string, mixed>  $page
     */
    private function harvest(
        Scan $scan,
        ShodanQuery $query,
        ?string $appliedFilter,
        array $page,
        string $decision = ScanStep::DECISION_HARVESTED,
        ?string $note = null,
    ): int {
        $results = $page['results'];
        $total = $page['total_results'];
        $perPage = (int) config('geoscan.enumeration.per_page', 10);
        $pageLimit = (int) config('geoscan.enumeration.page_limit', 2);

        // Following pages, within what Shodan lets a visitor read.
        for ($number = 2; $number <= $pageLimit && $total > ($number - 1) * $perPage; $number++) {
            if (! $this->hasBudget($scan)) {
                break;
            }

            try {
                $next = $this->fetchSearchPage($scan, $query, $number);
            } catch (ScrapingException) {
                break;
            }

            $results = [...$results, ...$next['results']];
        }

        $new = $this->store($scan, $query, $results);

        $this->trace(
            $scan, $query, $appliedFilter, $total,
            harvested: count($results),
            newHosts: $new,
            decision: $decision,
            note: $note,
        );

        return $total;
    }

    /**
     * The query overflows: replace it with its slices.
     *
     * @param  array<string, mixed>  $page
     * @param  list<string>  $usedFacets
     */
    private function split(Scan $scan, ShodanQuery $query, ?string $appliedFilter, array $page, array $usedFacets): int
    {
        $total = $page['total_results'];
        $slices = $this->slicesFor($scan, $query, $page, $usedFacets);

        if ($slices === null) {
            // No facet left to split this branch. Walking away empty handed
            // would be absurd: Shodan still shows the first 20, and 20 beats
            // zero. Measured 2026-09-03: an abandoned branch holding 21 results
            // was costing us all 21 hosts.
            $this->harvest(
                $scan, $query, $appliedFilter, $page,
                decision: ScanStep::DECISION_ABANDONED,
                note: 'plus aucune facette pour découper : seuls les résultats visibles sont pris',
            );

            return $total;
        }

        [$facetType, $values] = $slices;
        $lots = $this->lotsFrom($facetType, $values);

        $this->trace(
            $scan, $query, $appliedFilter, $total, 0, 0,
            ScanStep::DECISION_SPLIT,
            sprintf(
                '%d tranches sur la facette %s, groupées en %d requêtes',
                count($values), $facetType, count($lots),
            ),
        );

        foreach ($lots as $lot) {
            if (! $this->hasBudget($scan)) {
                break;
            }

            $this->explore(
                $scan,
                $query->withFilter($lot['filter']),
                [...$usedFacets, $facetType],
                $lot['filter'],
            );
        }

        $this->probeResidual($scan, $query, $appliedFilter, $facetType, $values, $total, $usedFacets);

        return $total;
    }

    /**
     * Packs slices into batches answerable by a single request.
     *
     * Shodan accepts a comma separated value list inside one filter --
     * "port:8081,8888,19222" behaves as an OR. The vast majority of slices in a
     * full ranking weigh exactly one result, so querying them individually
     * spends one request, and therefore ten seconds of crawl delay, per host.
     * Measured 2026-09-03 on the pool of 81 Swedish yawcam webcams: 77 port
     * slices, meaning 78 requests and 13 minutes one by one, against about ten
     * once batched.
     *
     * A batch targets per_page rather than the ceiling of 20, so a single page
     * empties it and page 2 stays as headroom. That headroom earns its keep:
     * Shodan's announced total runs slightly optimistic (measured: 7 announced,
     * 6 blocks actually served).
     *
     * A slice that overflows on its own is never batched: it goes back to
     * explore() as-is, to be split further.
     *
     * @param  list<array<string, mixed>>  $values  sorted by descending count
     * @return list<array{filter: string, count: int}>
     */
    private function lotsFrom(string $facetType, array $values): array
    {
        if (! $this->isBatchable($facetType)) {
            return array_map(
                fn (array $value): array => ['filter' => $value['filter'], 'count' => $value['count']],
                $values,
            );
        }

        $target = max(1, (int) config('geoscan.enumeration.per_page', 10));
        $lots = [];
        $pending = [];
        $pendingCount = 0;

        foreach ($values as $value) {
            if ($value['count'] > $target) {
                $lots[] = ['filter' => $value['filter'], 'count' => $value['count']];

                continue;
            }

            if ($pending !== [] && $pendingCount + $value['count'] > $target) {
                $lots[] = $this->mergeFilters($pending, $pendingCount);
                $pending = [];
                $pendingCount = 0;
            }

            $pending[] = $value['filter'];
            $pendingCount += $value['count'];
        }

        if ($pending !== []) {
            $lots[] = $this->mergeFilters($pending, $pendingCount);
        }

        return $lots;
    }

    /**
     * Merges several tokens of one facet into a single one: "port:80" and
     * "port:443" become "port:80,443".
     *
     * @param  list<string>  $filters
     * @return array{filter: string, count: int}
     */
    private function mergeFilters(array $filters, int $count): array
    {
        if (count($filters) === 1) {
            return ['filter' => $filters[0], 'count' => $count];
        }

        $key = strstr($filters[0], ':', before_needle: true);

        $values = array_map(
            fn (string $filter): string => substr($filter, strpos($filter, ':') + 1),
            $filters,
        );

        return ['filter' => $key.':'.implode(',', $values), 'count' => $count];
    }

    /**
     * Goes after whatever the partition left behind.
     *
     * When a ranking's counts sum to less than the total, the difference is a
     * tail that neither the search page nor "More..." ever names. We cannot
     * reach it by adding a filter -- we do not know which one -- but we can
     * describe it in the negative, by excluding every known value:
     * "-port:8081,8888,...". What remains is exactly the blind spot, and it
     * harvests like any other slice.
     *
     * This is what separates assumed coverage from proven coverage: if this
     * query announces 0, nothing is missing.
     *
     * @param  list<array<string, mixed>>  $values
     * @param  list<string>  $usedFacets
     */
    private function probeResidual(
        Scan $scan,
        ShodanQuery $query,
        ?string $appliedFilter,
        string $facetType,
        array $values,
        int $total,
        array $usedFacets,
    ): void {
        if (! $this->isBatchable($facetType) || ! $this->hasBudget($scan)) {
            return;
        }

        $covered = array_sum(array_column($values, 'count'));

        if ($covered >= $total) {
            return;
        }

        $negation = '-'.$this->mergeFilters(array_column($values, 'filter'), $covered)['filter'];

        $residual = $query->withFilter($negation);

        // Every round ADDS a negation token: by the third the query carries
        // "-port:<100> -port:<73> -port:<...>". It is therefore the length of
        // the WHOLE query that bounds the descent, not that of the last token.
        // Past a point Shodan's edge rejects the URL before it reaches the
        // engine -- better to stop and say so than to hit an opaque 414.
        $length = strlen($residual->toString());

        if ($length > (int) config('geoscan.enumeration.max_query_length', 2000)) {
            $this->trace(
                $scan, $query, $appliedFilter, $total, 0, 0,
                ScanStep::DECISION_ABANDONED,
                sprintf('requête de négation trop longue (%d caractères) pour aller plus loin', $length),
            );

            return;
        }

        // The facet is deliberately NOT marked as consumed. A positive filter
        // exhausts a dimension -- inside "port:80" there is nothing left to
        // split by port. A negation does not: "-port:<the first 100>" has a
        // port ranking of its own, and it holds the NEXT 100. Shodan caps every
        // facet page at 100 values (measured 2026-09-03), so coming back
        // through the same dimension is the only way past it. Each round
        // excludes strictly more values than the last, so the residual shrinks
        // and the descent terminates.
        $this->explore($scan, $residual, $usedFacets, $negation);
    }

    /** A facet whose values list unambiguously inside a single filter. */
    private function isBatchable(string $facetType): bool
    {
        return in_array($facetType, (array) config('geoscan.enumeration.batchable_facets', []), true);
    }

    /**
     * Picks the splitting facet and returns its values, or null when no usable
     * facet remains for this branch.
     *
     * Slices are sorted by DESCENDING count, and that direction matters.
     *
     * The "More..." page returns a ranking's full list, often several dozen
     * values of which the vast majority weigh a single result. Starting with
     * the smallest -- which an early version did -- burns the budget on
     * one-result slices without ever reaching the ones carrying the mass.
     * Measured 2026-09-01 on a real case: 30 requests spent, 39 results
     * harvested, and port:80 (29 results by itself) never visited.
     *
     * Going downward, an oversized slice does cost an extra split, but that is
     * where the substance is; singletons trail at the end and cost one result
     * each if they are dropped.
     *
     * @param  array<string, mixed>  $page
     * @param  list<string>  $usedFacets
     * @return array{0: string, 1: list<array<string, mixed>>}|null
     */
    private function slicesFor(Scan $scan, ShodanQuery $query, array $page, array $usedFacets): ?array
    {
        foreach ((array) config('geoscan.enumeration.split_facets', []) as $type) {
            if (in_array($type, $usedFacets, true)) {
                continue;
            }

            $values = $this->facetValues($scan, $query, $page, $type);

            if ($values === []) {
                continue;
            }

            usort($values, fn (array $a, array $b) => $b['count'] <=> $a['count']);

            return [$type, $values];
        }

        return null;
    }

    /**
     * A facet's values, expanded when it is worth it.
     *
     * The search page only shows the first 5 values of each ranking. When their
     * sum stays below the total, the difference is an invisible tail: the
     * "More..." link (/search/facet) reveals it, at the cost of one request. We
     * only pay that cost when the remaining budget comfortably allows it.
     *
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private function facetValues(Scan $scan, ShodanQuery $query, array $page, string $type): array
    {
        $values = array_values(array_filter(
            $page['facets'],
            fn (array $facet) => $facet['type'] === $type && filled($facet['filter']) && $facet['count'] > 0,
        ));

        if ($values === []) {
            return [];
        }

        $covered = array_sum(array_column($values, 'count'));

        $worthExpanding = $covered < $page['total_results']
            && (bool) config('geoscan.enumeration.expand_facets', true)
            && $this->remainingBudget($scan) > 3;

        if (! $worthExpanding) {
            return $values;
        }

        try {
            $expanded = $this->fetchFacetPage($scan, $query, $type);
        } catch (ScrapingException) {
            return $values;   // falling back to the top 5 is still valid
        }

        $expanded = array_values(array_filter(
            $expanded,
            fn (array $facet) => filled($facet['filter']) && $facet['count'] > 0,
        ));

        return count($expanded) > count($values) ? $expanded : $values;
    }

    /**
     * Stores a sub-query's results, without duplicates.
     *
     * The same IP necessarily reappears across several slices -- that is the
     * very principle of splitting. De-duplication happens at two levels: in
     * memory for this run, and through a unique constraint in the database.
     *
     * @param  list<array<string, mixed>>  $results
     * @return int number of genuinely new observations
     */
    private function store(Scan $scan, ShodanQuery $query, array $results): int
    {
        $new = 0;

        foreach ($results as $result) {
            $key = $result['ip'].':'.($result['port'] ?? '-');

            if (isset($this->seen[$key])) {
                continue;
            }

            $this->seen[$key] = true;

            DB::transaction(function () use ($scan, $query, $result, &$new): void {
                $host = Host::firstOrCreate(['ip' => $result['ip']]);

                // firstOrCreate rather than create: the (scan_id, ip, port)
                // unique constraint is the last line of defence against
                // duplicates, and we would rather query it than trip it.
                $observation = $scan->results()->firstOrCreate(
                    ['ip' => $result['ip'], 'port' => $result['port']],
                    [
                        'host_id' => $host->id,
                        'country_code' => $result['country_code'],
                        'country' => $result['country'],
                        'city' => $result['city'],
                        'organization' => $result['organization'],
                        'product' => $this->productFrom($query),
                        'hostnames' => $result['hostnames'],
                        'tags' => $result['tags'],
                        'technologies' => $result['technologies'],
                        'banner' => $result['banner'],
                        'matched_query' => $query->toString(),
                        ...ScanResult::timeParts($result['observed_at']),
                    ],
                );

                $new += $observation->wasRecentlyCreated ? 1 : 0;
            });
        }

        return $new;
    }

    /**
     * The product never appears in a result block, but when IT is what we
     * sliced on we do know it: it is right there in the query.
     */
    private function productFrom(ShodanQuery $query): ?string
    {
        foreach ($query->filters() as $filter) {
            if (str_starts_with($filter, 'product:')) {
                return trim(substr($filter, strlen('product:')), '"');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ScrapingException
     */
    private function fetchSearchPage(Scan $scan, ShodanQuery $query, int $page = 1): array
    {
        $parameters = ['query' => $query->toString()];

        if ($page > 1) {
            $parameters['page'] = (string) $page;
        }

        $this->countRequest($scan);

        return $this->searchParser->parse($this->client->get('/search', $parameters));
    }

    /**
     * Counts the request and publishes the counter.
     *
     * Writing immediately is not cosmetic: a scan runs for minutes, and without
     * it the view would show "0 requests" from start to finish, indistinguishable
     * from a stalled worker.
     */
    private function countRequest(Scan $scan): void
    {
        $this->requests++;

        $scan->forceFill(['requests_used' => $this->requests])->save();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ScrapingException
     */
    private function fetchFacetPage(Scan $scan, ShodanQuery $query, string $type): array
    {
        $this->countRequest($scan);

        return $this->facetParser->parse(
            $this->client->get('/search/facet', ['query' => $query->toString(), 'facet' => $type]),
            $type,
        );
    }

    /** Le nombre de resultats qu'un compte gratuit peut reellement consulter. */
    private function ceiling(): int
    {
        return (int) config('geoscan.enumeration.page_limit', 2)
            * (int) config('geoscan.enumeration.per_page', 10);
    }

    private function hasBudget(Scan $scan): bool
    {
        return $this->remainingBudget($scan) > 0;
    }

    private function remainingBudget(Scan $scan): int
    {
        return max(0, $scan->max_requests - $this->requests);
    }

    /** Appends one line to the scan's trace. */
    private function trace(
        Scan $scan,
        ShodanQuery $query,
        ?string $appliedFilter,
        int $total,
        int $harvested,
        int $newHosts,
        string $decision,
        ?string $note = null,
    ): void {
        $scan->steps()->create([
            'position' => ++$this->position,
            'depth' => $query->depth(),
            'query' => $query->toString(),
            'applied_filter' => $appliedFilter,
            'total_results' => $total,
            'harvested' => $harvested,
            'new_hosts' => $newHosts,
            'decision' => $decision,
            'note' => $note,
        ]);
    }
}
