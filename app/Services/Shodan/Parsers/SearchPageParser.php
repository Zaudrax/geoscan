<?php

namespace App\Services\Shodan\Parsers;

use App\Exceptions\ScrapingException;
use App\Services\Shodan\Parsers\Concerns\ExtractsFacetLists;
use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts the useful content of a Shodan /search page.
 *
 * A pure function: HTML in, array out. No network, no database -> testable at
 * will against a local copy of the page.
 *
 * Targeted structure (observed 2026-08-31):
 *
 *   <div class="summary">
 *     <h4 class="total-results">53,611,312</h4>
 *     <h6>Top Countries</h6>
 *     <ul class="facet-list">
 *       <li><a href="/search?query=nginx+country%3A%22US%22">United States</a><span>12,987,065</span></li>
 *       ...
 *       <li><a>More...</a></li>          <- no <span>: skipped
 *     </ul>
 *   <div class="result">
 *     <div class="heading"><a href="/host/1.2.3.4" class="title">…</a><a href="https://1.2.3.4:5263">…</a>
 *       <div class="timestamp">2026-08-31T07:47:03</div>
 *     <div class="result-details"><ul>
 *       <li class="hostnames">…</li>
 *       <li><a class="filter-link filter-org">…</a></li>
 *       <li><a href="…country%3A%22JP%22">Japan</a>, <a href="…city%3A%22Oi%22">Oi</a></li>
 *       <li class="components"><a data-tip="Nginx">…</a></li>
 *       <li class="tags"><a class="tag">cloud</a></li>
 *     <div class="banner-data"><pre>HTTP/1.1 404 Not Found…</pre>
 *   <div class="pagination"><a href="/search?query=nginx&page=2">Next</a>
 */
class SearchPageParser
{
    use ExtractsFacetLists;

    /**
     * Block heading as displayed by Shodan -> type stored in our database.
     * Shodan shows "Top Countries" to anonymous visitors and "Top Cities" in
     * some contexts: both are supported.
     */
    private const FACET_TYPES = [
        'top countries' => 'country',
        'top cities' => 'city',
        'top ports' => 'port',
        'top organizations' => 'org',
        'top products' => 'product',
        'top operating systems' => 'os',
    ];

    /**
     * @return array{
     *     total_results: int,
     *     facets: list<array{type: string, label: string, filter: ?string, count: int, position: int}>,
     *     results: list<array<string, mixed>>,
     *     next_page: ?int
     * }
     *
     * @throws ScrapingException
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);

        return [
            'total_results' => $this->extractTotalResults($crawler),
            'facets' => $this->facetListsIn($crawler),
            'results' => $this->extractResults($crawler),
            'next_page' => $this->extractNextPage($crawler),
        ];
    }

    /** @throws ScrapingException */
    private function extractTotalResults(Crawler $crawler): int
    {
        $node = $crawler->filter('.total-results');

        if ($node->count() === 0) {
            throw ScrapingException::unparsable('le nombre total de résultats');
        }

        return $this->toInt($node->first()->text());
    }

    /**
     * The individual result blocks: the raw material of the enumeration.
     *
     * @return list<array<string, mixed>>
     */
    private function extractResults(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('.result')->each(fn (Crawler $result) => $this->extractResult($result))
        ));
    }

    /** @return array<string, mixed>|null */
    private function extractResult(Crawler $result): ?array
    {
        $ip = $this->extractIp($result);

        // With no IP there is nothing to enumerate: this block is not a result.
        if ($ip === null) {
            return null;
        }

        $location = $this->extractLocation($result);

        return [
            'ip' => $ip,
            'port' => $this->extractPort($result),
            'service_url' => $this->extractServiceUrl($result),
            'hostnames' => $this->extractHostnames($result, $ip),
            'organization' => $this->firstText($result, '.filter-org'),
            'country_code' => $location['country_code'],
            'country' => $location['country'],
            'city' => $location['city'],
            'technologies' => $this->extractTechnologies($result),
            'tags' => $this->extractTags($result),
            'banner' => $this->extractBanner($result),
            'observed_at' => $this->extractObservedAt($result),
        ];
    }

    /** The title link points at the host page: /host/<ip>. */
    private function extractIp(Crawler $result): ?string
    {
        $link = $result->filter('a[href^="/host/"]');

        if ($link->count() === 0) {
            return null;
        }

        $ip = trim(substr((string) $link->first()->attr('href'), strlen('/host/')));

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /**
     * The port comes from the external "open service" link:
     * https://1.2.3.4:5263. When it is implicit, the scheme supplies it.
     */
    private function extractPort(Crawler $result): ?int
    {
        $external = $result->filter('.heading a[href^="http"]');

        if ($external->count() === 0) {
            return null;
        }

        $href = (string) $external->first()->attr('href');
        $port = parse_url($href, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        return match (parse_url($href, PHP_URL_SCHEME)) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
    }

    /**
     * The service URL exactly as Shodan offers it: the block's "open service"
     * link, scheme included. Kept verbatim rather than rebuilt from ip:port,
     * because the scheme (http vs https) cannot be guessed from a port number
     * alone.
     */
    private function extractServiceUrl(Crawler $result): ?string
    {
        $external = $result->filter('.heading a[href^="http"]');

        if ($external->count() === 0) {
            return null;
        }

        $href = trim((string) $external->first()->attr('href'));

        return $href !== '' ? $href : null;
    }

    /**
     * Shodan repeats the IP as the first <li class="hostnames">. We keep only
     * the genuine hostnames.
     *
     * @return list<string>
     */
    private function extractHostnames(Crawler $result, string $ip): array
    {
        $names = $result->filter('li.hostnames')->each(
            fn (Crawler $node) => $this->collapse($node->text())
        );

        return array_values(array_unique(array_filter(
            $names,
            fn (string $name) => $name !== '' && $name !== $ip,
        )));
    }

    /**
     * Country and city share a single line, each behind a filter link. The ISO
     * country code is only readable inside the href.
     *
     * @return array{country_code: ?string, country: ?string, city: ?string}
     */
    private function extractLocation(Crawler $result): array
    {
        $location = ['country_code' => null, 'country' => null, 'city' => null];

        $result->filter('a.filter-link')->each(function (Crawler $link) use (&$location): void {
            $filter = $this->filterTokenFrom($link);

            if ($filter === null) {
                return;
            }

            [$key, $value] = array_pad(explode(':', $filter, 2), 2, '');
            $value = trim($value, '"');

            if ($key === 'country' && $location['country_code'] === null) {
                $location['country_code'] = strtoupper($value);
                $location['country'] = $this->collapse($link->text());
            }

            if ($key === 'city' && $location['city'] === null) {
                $location['city'] = $this->collapse($link->text());
            }
        });

        return $location;
    }

    /** @return list<string> */
    private function extractTechnologies(Crawler $result): array
    {
        $names = $result->filter('li.components a[data-tip]')->each(
            fn (Crawler $node) => $this->collapse((string) $node->attr('data-tip'))
        );

        return array_values(array_unique(array_filter($names)));
    }

    /** @return list<string> */
    private function extractTags(Crawler $result): array
    {
        $tags = $result->filter('li.tags a.tag')->each(
            fn (Crawler $node) => $this->collapse($node->text())
        );

        return array_values(array_unique(array_filter($tags)));
    }

    /** "2026-08-31T07:47:03" -> Carbon, accurate to the second. */
    private function extractObservedAt(Crawler $result): ?Carbon
    {
        $node = $result->filter('.timestamp');

        if ($node->count() === 0) {
            return null;
        }

        $raw = $this->collapse($node->first()->text());

        if (! preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $raw, $matches)) {
            return null;
        }

        return Carbon::parse($matches[0]);
    }

    /** The next page number, or null when we have reached the end. */
    private function extractNextPage(Crawler $crawler): ?int
    {
        $next = null;

        $crawler->filter('.pagination a')->each(function (Crawler $link) use (&$next): void {
            $href = html_entity_decode((string) $link->attr('href'), ENT_QUOTES | ENT_HTML5);
            $query = parse_url($href, PHP_URL_QUERY);

            if (! is_string($query)) {
                return;
            }

            parse_str($query, $params);
            $page = (int) ($params['page'] ?? 0);

            // "Next" is the highest number offered: Shodan renders a single
            // next link plus, sometimes, a handful of page numbers.
            if ($page > 0 && ($next === null || $page > $next)) {
                $next = $page;
            }
        });

        return $next;
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $text = $this->collapse($node->first()->text());

        return $text !== '' ? $text : null;
    }

    /**
     * The banner is the only field where formatting matters: it is a raw HTTP
     * header, and its "Date:" line is where the second the enumeration relies
     * on is read. text() normalises whitespace by default, which would collapse
     * everything onto one line, so we forbid it.
     */
    private function extractBanner(Crawler $result): ?string
    {
        $node = $result->filter('.banner-data pre');

        if ($node->count() === 0) {
            return null;
        }

        $banner = trim($node->first()->text(null, normalizeWhitespace: false));

        return $banner !== '' ? $banner : null;
    }

    /** Collapses the runs of spaces and newlines that indented HTML leaves. */
    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
