<?php

namespace App\Services\Shodan;

use App\Exceptions\ScrapingException;
use App\Models\Search;
use App\Services\Shodan\Parsers\SearchPageParser;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates scraping one search: fetch -> extract -> archive.
 *
 * Every call creates a NEW archive. We never update an existing search: the
 * same query run on two different days is two distinct rows, and that is the
 * entire point of keeping a history.
 */
class SearchScraper
{
    public function __construct(
        private readonly ShodanClient $client,
        private readonly SearchPageParser $parser,
    ) {}

    /**
     * Scrapes one search, page by page.
     *
     * Shodan shows only 10 results per page. We follow the pagination to bring
     * back the whole pool, with two guards: a page cap, and stopping as soon as
     * a page adds no new host. That second guard covers the free account case,
     * capped at 2 pages: the next page returns the subscription wall instead of
     * results, hence nothing new.
     *
     * The total and the rankings are the ones from page 1: they do not change
     * from page to page.
     *
     * @throws ScrapingException
     */
    public function scrape(string $query): Search
    {
        $maxPages = max(1, (int) config('geoscan.search.max_pages', 12));

        $first = $this->parser->parse($this->fetch($query, 1));

        /** @var array<string, array<string, mixed>> $results */
        $results = $this->indexByHost($first['results']);

        for ($page = 2; $page <= $maxPages && count($results) < $first['total_results']; $page++) {
            try {
                $data = $this->parser->parse($this->fetch($query, $page));
            } catch (ScrapingException) {
                // Past its quota Shodan serves a subscription wall rather than
                // a result page, and the parser cannot read it. This is not a
                // failure: keep the hosts already collected and stop here.
                break;
            }

            $newcomers = array_diff_key($this->indexByHost($data['results']), $results);

            // A page with no new host means we are done: either we have it
            // all, or Shodan stopped serving results (free tier ceiling).
            if ($newcomers === []) {
                break;
            }

            $results += $newcomers;
        }

        return $this->store($query, [
            'total_results' => $first['total_results'],
            'facets' => $first['facets'],
            'results' => array_values($results),
        ]);
    }

    /**
     * Fetches the HTML of one result page. The page parameter is only added
     * from the second onwards: Shodan treats its absence as page 1.
     *
     * @throws ScrapingException
     */
    private function fetch(string $query, int $page): string
    {
        $params = ['query' => $query];

        if ($page > 1) {
            $params['page'] = (string) $page;
        }

        return $this->client->get('/search', $params);
    }

    /**
     * Indexes results by (IP, port): the key that identifies a service, and
     * therefore the sieve that removes duplicates across pages.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    private function indexByHost(array $results): array
    {
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$result['ip'].':'.($result['port'] ?? '')] = $result;
        }

        return $indexed;
    }

    /**
     * Writes the archive in one transaction: a search and its rankings are
     * meaningless apart, so they land together or not at all.
     *
     * @param  array{total_results: int, facets: list<array{type: string, label: string, count: int, position: int}>, results: list<array<string, mixed>>}  $data
     */
    private function store(string $query, array $data): Search
    {
        return DB::transaction(function () use ($query, $data): Search {
            $search = Search::create([
                'query' => $query,
                'total_results' => $data['total_results'],
                'scraped_at' => now(),
            ]);

            $search->facets()->createMany($data['facets']);
            $search->results()->createMany($this->resultsFor($data['results']));

            return $search->load('facets', 'results');
        });
    }

    /**
     * Prepares collected hosts for insertion: keep only the table's columns and
     * freeze their display rank.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function resultsFor(array $results): array
    {
        return array_values(array_map(fn (array $result, int $position): array => [
            'ip' => $result['ip'],
            'port' => $result['port'] ?? null,
            'service_url' => $result['service_url'] ?? null,
            'country_code' => $result['country_code'] ?? null,
            'country' => $result['country'] ?? null,
            'city' => $result['city'] ?? null,
            'organization' => $result['organization'] ?? null,
            'hostnames' => $result['hostnames'] ?? [],
            'tags' => $result['tags'] ?? [],
            'technologies' => $result['technologies'] ?? [],
            'banner' => $result['banner'] ?? null,
            'observed_at' => $result['observed_at'] ?? null,
            'position' => $position,
        ], $results, array_keys($results)));
    }
}
