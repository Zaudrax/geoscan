<?php

namespace App\Http\Controllers;

use App\Jobs\RunScan;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Services\Exposure\ExposureScorer;
use App\Services\Geo\MapMarkers;
use App\Services\Shodan\ShodanQuery;
use App\Services\Shodan\ShodanSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Scans: the filter-splitting enumeration, and the view that reads it back.
 *
 * Nothing here scrapes. `store` only queues the work, because a scan takes
 * minutes; `show` reads our own rows exclusively, and its only possible
 * outbound call is geocoding a city we have never seen before.
 */
class ScanController extends Controller
{
    /** The form: a country, plus either a banner term or an instant. */
    public function create(ShodanSession $session): View
    {
        return view('scans.create', [
            'countries' => config('countries'),
            'isAuthenticated' => $session->isEnabled(),
            'maxRequests' => (int) config('geoscan.enumeration.max_requests', 30),
            'scans' => Scan::latest('started_at')->take(5)->get(),
        ]);
    }

    /**
     * Creates the scan and hands it to the queue.
     *
     * Nothing is scraped here: at ten seconds between requests a scan runs for
     * minutes. RunScan does the work while the scan view follows its progress.
     */
    public function store(Request $request): RedirectResponse
    {
        // Two mutually exclusive modes: a banner term to split, or a timestamp.
        // Supplying a term makes the instant optional.
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'in:'.implode(',', array_keys(config('countries')))],
            'base_term' => ['nullable', 'string', 'min:2', 'max:255'],
            'observed_on' => ['required_without:base_term', 'nullable', 'date_format:Y-m-d'],
            'observed_hour' => ['required_without:base_term', 'nullable', 'integer', 'between:0,23'],
            'observed_minute' => ['required_without:base_term', 'nullable', 'integer', 'between:0,59'],
            'observed_second' => ['nullable', 'integer', 'between:0,59'],
        ], attributes: [
            'country_code' => 'pays',
            'base_term' => 'terme',
            'observed_on' => 'date',
            'observed_hour' => 'heure',
            'observed_minute' => 'minute',
            'observed_second' => 'seconde',
        ]);

        $scan = Scan::create($this->attributesFrom($validated));

        RunScan::dispatch($scan);

        return redirect()
            ->route('scans.show', $scan)
            ->with('status', 'Scan lancé. La page se rafraîchit toute seule jusqu\'à la fin.');
    }

    /** The campaign history. */
    public function index(): View
    {
        return view('scans.index', [
            'scans' => Scan::withCount('results')->latest('started_at')->paginate(20),
        ]);
    }

    /**
     * Reading a scan: map, filters and results.
     *
     * No request to Shodan is triggered here. The left column's filters are
     * computed from our own rows, and the only possible outbound call is
     * geocoding a city we have never resolved before.
     */
    public function show(Request $request, Scan $scan, MapMarkers $markers, ExposureScorer $scorer): View
    {
        $filters = $request->only([
            'port', 'city', 'organization', 'product', 'tag',
            'country', 'date', 'hour', 'minute', 'second', 'q',
        ]);

        $results = $scan->results()
            ->filter($filters)
            ->orderBy('observed_at')
            ->orderBy('ip')
            ->get();

        return view('scans.show', [
            'scan' => $scan,
            'results' => $results,
            'filters' => array_filter($filters, fn ($value) => filled($value)),
            'facets' => ScanResult::facetsFrom($results),
            'markers' => $markers->for($results),
            'steps' => $scan->steps,
            'totalResults' => $scan->results()->count(),
            // Injected rather than resolved row by row in the view: the scorer
            // is stateless, one instance serves the whole table.
            'scorer' => $scorer,
        ]);
    }

    /**
     * Translates validated form input into the scan's stored columns.
     *
     * Kept apart from `store` because the two modes make this genuinely
     * branchy, and mixing it with validation made the action hard to read.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        $byTerm = filled($validated['base_term'] ?? null);

        return [
            'country_code' => $validated['country_code'],
            'base_term' => $byTerm ? $validated['base_term'] : null,
            'observed_on' => $byTerm ? null : $validated['observed_on'],
            'observed_hour' => $byTerm ? null : $validated['observed_hour'],
            'observed_minute' => $byTerm ? null : $validated['observed_minute'],
            'observed_second' => $byTerm ? null : ($validated['observed_second'] ?? null),
            'base_query' => $byTerm
                ? ShodanQuery::forCountryTerm($validated['country_code'], $validated['base_term'])->toString()
                : $this->timestampQueryFor($validated),
            'max_requests' => (int) config('geoscan.enumeration.max_requests', 30),
            'status' => Scan::STATUS_RUNNING,
            'started_at' => now(),
        ];
    }

    /**
     * The root Shodan query of a timestamped scan, as it will be sent.
     *
     * @param  array<string, mixed>  $validated
     */
    private function timestampQueryFor(array $validated): string
    {
        $second = $validated['observed_second'] ?? null;

        $moment = Carbon::parse($validated['observed_on'])->setTime(
            (int) $validated['observed_hour'],
            (int) $validated['observed_minute'],
            (int) ($second ?? 0),
        );

        $query = ShodanQuery::forCountryAt($validated['country_code'], $moment)->toString();

        // With no second pinned the root is not one query but sixty, so we show
        // the pattern being swept rather than an arbitrary second.
        return $second === null
            ? preg_replace('/(\d{2}:\d{2}):\d{2}/', '$1:xx', $query)
            : $query;
    }
}
