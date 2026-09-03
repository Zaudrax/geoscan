<?php

namespace App\Services\Shodan\Parsers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts the useful content of a Shodan /host/<ip> page.
 *
 * Like SearchPageParser: a pure function, no network, no database.
 *
 * Targeted structure (observed 2026-08-31):
 *
 *   <div class="top-info"><h6><span>Last Seen: 2026-08-31</span></h6></div>
 *   <div id="general">…<div class="grid-table">
 *       <label>Country</label><div><strong><a>United States</a></strong></div>
 *       <div class="grid-border"></div>            <- separator, skipped
 *       …
 *   <div id="http-components">
 *       <div class="category">
 *           <div class="category-heading">Security</div>
 *           <div class="technologies">…<span class="technology-name">HSTS</span>…
 *   <div id="ports"><a href="#53">53</a><a href="#443">443</a></div>
 *
 * Vulnerabilities, however, are NOT in the DOM. The page carries per-severity
 * counters (div.banner-cves) but keeps the identifiers in an inline script:
 *
 *   <script>(() => { const VULNS = {"CVE-2022-1609":{"cvss":9.8,"ports":[...],
 *                                   "summary":"..."}, ...};
 *
 * That is a richer source than the DOM -- identifier, CVSS score and summary in
 * one go -- but it forces us out of CSS selectors for that one block.
 */
class HostPageParser
{
    /**
     * Default cap on how many vulnerabilities we keep. It arrives through the
     * constructor rather than config(), so this parser stays what its header
     * promises: a pure function, testable without booting Laravel. The
     * application's value is injected in AppServiceProvider.
     */
    private const DEFAULT_MAX_VULNERABILITIES = 50;

    public function __construct(
        private readonly int $maxVulnerabilities = self::DEFAULT_MAX_VULNERABILITIES,
    ) {}

    /**
     * @return array{
     *     country: ?string, city: ?string, organization: ?string, isp: ?string,
     *     asn: ?string, hostnames: list<string>, domains: list<string>,
     *     web_technologies: list<array{category: string, name: string}>,
     *     open_ports: list<int>, shodan_last_seen: ?string,
     *     vulnerabilities: list<array{id: string, cvss: float|null, summary: string|null}>,
     *     vulnerability_count: int
     * }
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $general = $this->extractGeneralInformation($crawler);

        return [
            'country' => $general['country'] ?? null,
            'city' => $general['city'] ?? null,
            'organization' => $general['organization'] ?? null,
            'isp' => $general['isp'] ?? null,
            'asn' => $general['asn'] ?? null,
            'hostnames' => $general['hostnames'] ?? [],
            'domains' => $general['domains'] ?? [],
            'web_technologies' => $this->extractWebTechnologies($crawler),
            'open_ports' => $this->extractOpenPorts($crawler),
            'shodan_last_seen' => $this->extractLastSeen($crawler),
            ...$this->extractVulnerabilities($html),
        ];
    }

    /**
     * Known vulnerabilities, read from the inline script rather than the DOM.
     *
     * The stored list is capped: a honeypot can announce more than a thousand,
     * and a snapshot has no business weighing two megabytes. We keep the most
     * severe -- those are the ones anyone looks at -- and keep the EXACT count
     * beside them, so the interface never lies about what it is showing.
     *
     * @return array{vulnerabilities: list<array{id: string, cvss: float|null, summary: string|null}>, vulnerability_count: int}
     */
    private function extractVulnerabilities(string $html): array
    {
        $empty = ['vulnerabilities' => [], 'vulnerability_count' => 0];

        if (! preg_match('/const\s+VULNS\s*=\s*(\{.*?\})\s*;/s', $html, $matches)) {
            return $empty;
        }

        $decoded = json_decode($matches[1], true);

        if (! is_array($decoded) || $decoded === []) {
            return $empty;
        }

        $vulnerabilities = [];

        foreach ($decoded as $id => $details) {
            if (! is_string($id) || ! preg_match('/^CVE-\d{4}-\d+$/', $id)) {
                continue;
            }

            $summary = is_array($details) ? ($details['summary'] ?? null) : null;

            $vulnerabilities[] = [
                'id' => $id,
                'cvss' => is_array($details) && is_numeric($details['cvss'] ?? null)
                    ? (float) $details['cvss']
                    : null,
                'summary' => is_string($summary) ? Str::limit(trim($summary), 300) : null,
            ];
        }

        // Most severe first: this is the display order, and it also decides
        // which ones survive the cap.
        usort(
            $vulnerabilities,
            fn (array $a, array $b) => ($b['cvss'] ?? 0) <=> ($a['cvss'] ?? 0),
        );

        return [
            'vulnerabilities' => array_slice($vulnerabilities, 0, max(1, $this->maxVulnerabilities)),
            'vulnerability_count' => count($vulnerabilities),
        ];
    }

    /**
     * The "General Information" block is a run of
     * <label>key</label><div>value</div> pairs.
     *
     * @return array<string, string|list<string>>
     */
    private function extractGeneralInformation(Crawler $crawler): array
    {
        $heading = $crawler->filter('#general');

        // The table follows the "General Information" heading; on a page whose
        // structure changed we fall back to the first .grid-table we find.
        $table = $heading->count() > 0
            ? $heading->nextAll()->filter('.grid-table')
            : $crawler->filter('.grid-table');

        if ($table->count() === 0) {
            $table = $crawler->filter('.grid-table');
        }

        if ($table->count() === 0) {
            return [];
        }

        $fields = [];

        $table->first()->filter('label')->each(function (Crawler $label) use (&$fields): void {
            $key = $this->normalizeKey($label->text());

            if ($key === null) {
                return;
            }

            // The value is the element right after the <label>; empty
            // <div class="grid-border"> separators are skipped.
            $value = $label->nextAll()->reduce(
                fn (Crawler $node) => ! $node->matches('.grid-border')
            );

            if ($value->count() === 0) {
                return;
            }

            // Hostnames and Domains are lists: each entry has its own tag.
            // They must be read one by one, otherwise text() glues the values
            // together ("harvard.edu" + "kaltura.com" -> "harvard.edukaltura.com").
            $fields[$key] = in_array($key, ['hostnames', 'domains'], strict: true)
                ? $this->listFrom($value->first())
                : $this->collapse($value->first()->text());
        });

        return $fields;
    }

    /** Libelle Shodan -> nom de colonne. */
    private function normalizeKey(string $label): ?string
    {
        return match (Str::of($label)->trim()->lower()->toString()) {
            'country' => 'country',
            'city' => 'city',
            'organization' => 'organization',
            'isp' => 'isp',
            'asn' => 'asn',
            'hostnames' => 'hostnames',
            'domains' => 'domains',
            default => null,
        };
    }

    /** @return list<array{category: string, name: string}> */
    private function extractWebTechnologies(Crawler $crawler): array
    {
        $technologies = [];

        $crawler->filter('#http-components .category')
            ->each(function (Crawler $category) use (&$technologies): void {
                $heading = $category->filter('.category-heading');
                $group = $heading->count() > 0 ? $this->collapse($heading->text()) : 'Autres';

                $category->filter('.technology-name')
                    ->each(function (Crawler $name) use (&$technologies, $group): void {
                        $technologies[] = [
                            'category' => $group,
                            'name' => $this->collapse($name->text()),
                        ];
                    });
            });

        return $technologies;
    }

    /** @return list<int> */
    private function extractOpenPorts(Crawler $crawler): array
    {
        $ports = $crawler->filter('#ports a')->each(
            fn (Crawler $link) => (int) preg_replace('/\D+/', '', $link->text())
        );

        return array_values(array_unique(array_filter($ports)));
    }

    /** "Last Seen: 2026-08-31" -> "2026-08-31" */
    private function extractLastSeen(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.top-info');

        if ($node->count() === 0) {
            return null;
        }

        if (! preg_match('/(\d{4}-\d{2}-\d{2})/', $node->first()->text(), $matches)) {
            return null;
        }

        return Carbon::parse($matches[1])->toDateString();
    }

    /**
     * Extracts a list of values from a cell, preferring child tags (<a> for
     * domains, <b> for hostnames) and falling back to splitting the text when
     * the cell is flat.
     *
     * @return list<string>
     */
    private function listFrom(Crawler $cell): array
    {
        // The "Domains" case: one <a> tag per value.
        $links = $cell->filter('a');

        if ($links->count() > 0) {
            return $this->clean($links->each(fn (Crawler $node) => $node->text()));
        }

        // The "Hostnames" case: values are separated by <br>, and the <b> only
        // wraps the domain part of the name, not the whole name:
        //     lifelabtenant.wireless.med.<b>harvard.edu</b><br>
        // Splitting on <br> then stripping tags yields the complete name.
        $lines = preg_split('/<br\s*\/?>/i', $cell->html()) ?: [];

        return $this->clean(array_map(
            fn (string $line) => html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5),
            $lines
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function clean(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $value) => $this->collapse($value), $values),
            fn (string $value) => $value !== '',
        )));
    }

    /** Ecrase les espaces multiples et retours ligne du HTML indente. */
    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
