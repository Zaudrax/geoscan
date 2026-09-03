<?php

namespace App\Services\Shodan;

use App\Exceptions\ScrapingException;
use App\Models\OutboundRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single HTTP client for every request to shodan.io.
 *
 * It carries the entire crawl policy on its own:
 *   1. an identifiable User-Agent, sent on every request;
 *   2. a minimum delay between outbound requests (config geoscan.request_delay,
 *      defaulted to the "Crawl-delay: 10" that shodan.io/robots.txt announces);
 *   3. a flat refusal of paths robots.txt disallows;
 *   4. attaching the session opened by ShodanSession when an account is
 *      configured -- without it Shodan rejects every search filter;
 *   5. bounded retries on server errors: shodan.io's edge intermittently
 *      returns a 503 "upstream connect error" unrelated to our request. Without
 *      a retry, a scan of dozens of sub-requests dies on the first hiccup;
 *   6. recording every attempt in the compliance journal, including the ones it
 *      refuses to send.
 *
 * No other part of the code is allowed to call Shodan directly.
 */
class ShodanClient
{
    /** Timestamp of the last outbound request, shared across web requests. */
    private const THROTTLE_KEY = 'geoscan:last_request_at';

    public function __construct(
        private readonly ShodanSession $session,
        private readonly string $baseUrl = '',
        private readonly ?int $delay = null,
    ) {}

    /**
     * Fetches the raw HTML of a shodan.io path.
     *
     * @param  string  $path  absolute path, e.g. "/search" or "/host/8.8.8.8"
     * @param  array<string, string>  $query
     *
     * @throws ScrapingException
     */
    public function get(string $path, array $query = []): string
    {
        $body = $this->send($path, $query);

        if (! $this->looksLikeLoginWall($body)) {
            return $body;
        }

        // Shodan served us the login wall. With no account configured there is
        // nothing to try. With one, the session expired server side: reconnect
        // and replay the request, exactly once.
        if (! $this->session->isEnabled()) {
            throw ScrapingException::loginRequired();
        }

        $this->session->refresh();

        $body = $this->send($path, $query);

        if ($this->looksLikeLoginWall($body)) {
            throw ScrapingException::loginRequired();
        }

        return $body;
    }

    /**
     * One HTTP round trip, with retries on server errors.
     *
     * Only 5xx are replayed: a 404 or a 403 will not change its mind, and
     * insisting would just be noise. Attempts are naturally spaced by the
     * courtesy delay, so there is no extra backoff to compute.
     *
     * @param  array<string, string>  $query
     *
     * @throws ScrapingException
     */
    private function send(string $path, array $query): string
    {
        $attempts = max(1, (int) config('geoscan.retries', 2) + 1);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($path, $query);
            } catch (ScrapingException $e) {
                // A transient shodan.io failure deserves a second chance; a
                // response aimed at us does not.
                if ($attempt >= $attempts || ! $e->isTransient()) {
                    throw $e;
                }

                Log::info('geoscan.retry', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * One HTTP attempt, courtesy delay included.
     *
     * @param  array<string, string>  $query
     *
     * @throws ScrapingException
     */
    private function attempt(string $path, array $query): string
    {
        $this->guardAgainstRobots($path);
        $waited = $this->respectCrawlDelay();

        $url = rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');

        $timeout = (int) config('geoscan.timeout', 20);

        try {
            $request = Http::withHeaders([
                'User-Agent' => config('geoscan.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout($timeout);

            if ($jar = $this->session->jar()) {
                $request = $request->withOptions(['cookies' => $jar]);
            }

            $response = $request->get($url, $query);
        } catch (ConnectionException $e) {
            // Timeout, DNS, dropped connection: we still mark the request as
            // sent so we do not immediately hammer a struggling site, and we
            // surface a message a human can act on.
            $this->markRequestSent();
            $this->journal($path, $query, OutboundRequest::OUTCOME_UNREACHABLE, $waited, null,
                "aucune réponse dans le délai de {$timeout} s");

            throw ScrapingException::unreachable($url, $timeout);
        }

        $this->markRequestSent();

        Log::info('geoscan.http', [
            'url' => $url,
            'query' => $query,
            'status' => $response->status(),
            'authenticated' => $this->session->isEnabled(),
        ]);

        if (! $response->successful()) {
            $this->journal($path, $query, OutboundRequest::OUTCOME_FAILED, $waited, $response->status());

            throw ScrapingException::httpFailure($url, $response->status());
        }

        $this->journal($path, $query, OutboundRequest::OUTCOME_SENT, $waited, $response->status());

        return $response->body();
    }

    /** The delay actually enforced between two requests, in seconds. */
    public function delay(): int
    {
        return $this->delay ?? (int) config('geoscan.request_delay', 10);
    }

    private function baseUrl(): string
    {
        return $this->baseUrl !== '' ? $this->baseUrl : (string) config('geoscan.base_url');
    }

    /**
     * robots.txt is authoritative: the request is never even built.
     *
     * @throws ScrapingException
     */
    private function guardAgainstRobots(string $path): void
    {
        $normalized = '/'.ltrim(parse_url($path, PHP_URL_PATH) ?? $path, '/');

        foreach ((array) config('geoscan.disallowed_paths', []) as $disallowed) {
            if (Str::startsWith($normalized, $disallowed)) {
                // Journalled EVEN THOUGH nothing goes out: a refused request is
                // the evidence that the guard works, and belongs in the log
                // just as much as one that was sent.
                $this->journal($normalized, [], OutboundRequest::OUTCOME_BLOCKED_BY_ROBOTS, null, null,
                    "chemin interdit par robots.txt ({$disallowed})");

                throw ScrapingException::disallowedByRobots($normalized);
            }
        }
    }

    /**
     * Waits, if needed, for the minimum delay to elapse.
     *
     * Returns the time ACTUALLY waited, in seconds: that is the value the
     * compliance journal records. An asserted crawl policy and a measured one
     * are not worth the same.
     */
    private function respectCrawlDelay(): float
    {
        $delay = $this->delay();

        if ($delay <= 0) {
            return 0.0;
        }

        $last = Cache::get(self::THROTTLE_KEY);

        if ($last === null) {
            return 0.0;
        }

        $waitFor = $delay - (microtime(true) - (float) $last);

        if ($waitFor <= 0) {
            return 0.0;
        }

        usleep((int) round($waitFor * 1_000_000));

        return round($waitFor, 3);
    }

    private function markRequestSent(): void
    {
        Cache::put(self::THROTTLE_KEY, microtime(true), now()->addHour());
    }

    /**
     * Records one outbound call, attempted or refused.
     *
     * We store intent and outcome, never content: neither the HTML received nor
     * the cookies sent. A compliance journal must not become a second copy of
     * the data, and even less a store of secrets.
     *
     * A failure to write the journal must never fail a scan: the journal
     * observes the work, it does not govern it.
     *
     * @param  array<string, string>  $query
     */
    private function journal(
        string $path,
        array $query,
        string $outcome,
        ?float $waited,
        ?int $status,
        ?string $note = null,
    ): void {
        try {
            OutboundRequest::create([
                'service' => 'shodan',
                'path' => $path,
                'query' => $query === [] ? null : ($query['query'] ?? http_build_query($query)),
                'status' => $status,
                'outcome' => $outcome,
                'note' => $note,
                'waited_seconds' => $waited,
                'authenticated' => $this->session->isEnabled(),
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('geoscan.journal_failed', ['path' => $path, 'reason' => $e->getMessage()]);
        }
    }

    /** Shodan answers 200 with an error banner rather than a dedicated status. */
    private function looksLikeLoginWall(string $html): bool
    {
        return Str::contains($html, 'Please log in to use search filters', ignoreCase: true);
    }
}
