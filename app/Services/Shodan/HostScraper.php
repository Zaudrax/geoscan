<?php

namespace App\Services\Shodan;

use App\Exceptions\ScrapingException;
use App\Models\Host;
use App\Models\HostSnapshot;
use App\Services\Shodan\Parsers\HostPageParser;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the scraping of a single host page.
 *
 * Assignment rule (step 9): every visit attempts a fresh fetch, BUT a guard
 * reuses the existing snapshot when it is younger than
 * geoscan.host_cooldown seconds. That guard is the difference between
 * "we scrape on every click" and "we scrape sparingly, when it is useful".
 */
class HostScraper
{
    public function __construct(
        private readonly ShodanClient $client,
        private readonly HostPageParser $parser,
    ) {}

    /**
     * Returns the current snapshot for an IP, fetching a new one if the
     * cooldown has elapsed.
     *
     * @throws ScrapingException
     */
    public function fetch(string $ip): HostFetchResult
    {
        $host = Host::firstOrCreate(['ip' => $ip]);

        if ($fresh = $this->recentSnapshot($host)) {
            return new HostFetchResult($fresh, reusedExisting: true);
        }

        $html = $this->client->get("/host/{$ip}");
        $data = $this->parser->parse($html);

        return new HostFetchResult(
            $this->store($host, $data),
            reusedExisting: false,
        );
    }

    /** The still warm snapshot, or null when a fresh fetch is warranted. */
    private function recentSnapshot(Host $host): ?HostSnapshot
    {
        $cooldown = (int) config('geoscan.host_cooldown', 300);

        if ($cooldown <= 0) {
            return null;
        }

        return $host->snapshots()
            ->where('fetched_at', '>=', now()->subSeconds($cooldown))
            ->first();
    }

    /**
     * Persists a new snapshot. Never an update in place: the timeline exists
     * precisely because past snapshots stay untouched.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(Host $host, array $data): HostSnapshot
    {
        return DB::transaction(
            fn () => $host->snapshots()->create($data + ['fetched_at' => now()])
        );
    }
}
