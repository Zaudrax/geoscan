<?php

namespace App\Services\Shodan\Parsers\Concerns;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Reading <ul class="facet-list"> blocks, shared by the search page and the
 * /search/facet page.
 *
 * The important part is the filter token. Shodan writes the complete replayable
 * query into every href:
 *
 *     /search?query=nginx+org%3A%22Meteverse+Limited.%22
 *
 * Rebuilding it from the displayed label ("Meteverse Limited.") is a trap:
 * Shodan turns commas into spaces, keeps periods, and only adds quotes when
 * there is a space. So we lift the token verbatim from the end of the decoded
 * query instead.
 */
trait ExtractsFacetLists
{
    /**
     * @return list<array{type: string, label: string, filter: ?string, count: int, position: int}>
     */
    protected function facetListsIn(Crawler $crawler, ?string $forcedType = null): array
    {
        $facets = [];

        $crawler->filter('.facet-list')->each(function (Crawler $list) use (&$facets, $forcedType): void {
            $type = $forcedType ?? $this->resolveType($this->headingFor($list));

            if ($type === null) {
                return; // unknown block: skip it rather than store nonsense
            }

            $position = 0;

            $list->filter('li')->each(function (Crawler $row) use (&$facets, &$position, $type): void {
                $count = $row->filter('span');
                $label = $row->filter('a');

                // The last <li> is the "More..." link: no count, so skip it.
                if ($count->count() === 0 || $label->count() === 0) {
                    return;
                }

                $facets[] = [
                    'type' => $type,
                    'label' => trim($label->first()->text()),
                    'filter' => $this->filterTokenFrom($label->first()),
                    'count' => $this->toInt($count->first()->text()),
                    'position' => $position++,
                ];
            });
        });

        return $facets;
    }

    /**
     * Lifts the last filter out of the query carried by the link.
     *
     * "/search?query=nginx+port%3A80"                -> "port:80"
     * "/search?query=nginx+org%3A%22Big+Corp.%22"    -> 'org:"Big Corp."'
     */
    protected function filterTokenFrom(Crawler $link): ?string
    {
        $href = $link->attr('href');

        if ($href === null) {
            return null;
        }

        $query = parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY);

        if ($query === null || $query === false) {
            return null;
        }

        parse_str($query, $params);
        $shodanQuery = trim((string) ($params['query'] ?? ''));

        if ($shodanQuery === '') {
            return null;
        }

        // A filter is "key:value", where the value may be quoted.
        if (! preg_match('/([a-z][a-z0-9_.]*:(?:"[^"]*"|\S+))\s*$/i', $shodanQuery, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** Walks back to the <h6> before the list to learn which ranking it is. */
    protected function headingFor(Crawler $list): string
    {
        $heading = $list->previousAll()->filter('h6');

        return $heading->count() > 0 ? trim($heading->first()->text()) : '';
    }

    /** Maps a displayed heading to our stored facet type, or null if unknown. */
    protected function resolveType(string $heading): ?string
    {
        return self::FACET_TYPES[mb_strtolower(trim($heading))] ?? null;
    }

    /** "12,987,065" -> 12987065 */
    protected function toInt(string $raw): int
    {
        return (int) preg_replace('/\D+/', '', $raw);
    }
}
