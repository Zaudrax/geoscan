<?php

namespace App\Services\Shodan\Parsers;

use App\Services\Shodan\Parsers\Concerns\ExtractsFacetLists;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts a facet's long list: /search/facet?query=...&facet=product.
 *
 * This is the "More..." link under each ranking on the search page, and its
 * value to the enumeration is direct: the search page shows only the first 5
 * values, this one returns all of them -- up to Shodan's cap of 100 (measured
 * 2026-09-03). On a real case (measured 2026-09-01, country:"PL" port:80 on a
 * given second) it yields 8 products against 5, i.e. 3 slices of results that
 * were otherwise unreachable.
 *
 * Its structure has nothing in common with the search page rankings: no
 * <ul class="facet-list">, no <li>, but a flat run of alternating <div> inside
 * a single card:
 *
 *   <div class="card … facets-card">
 *     <div class="name"><a href="/search?query=…+product%3A%22nginx%22"><strong>nginx</strong></a></div>
 *     <div class="value">8</div>
 *     <div class="bar">…</div>
 *     <div class="name">…</div>
 *     <div class="value">7</div>
 *     …
 *
 * The type cannot be guessed here: the caller knows which facet it asked for,
 * so it passes it in.
 */
class FacetPageParser
{
    use ExtractsFacetLists;

    /**
     * Present to satisfy the trait: on this page the type is imposed by the
     * caller, so no heading ever needs translating.
     */
    private const FACET_TYPES = [];

    /**
     * @return list<array{type: string, label: string, filter: ?string, count: int, position: int}>
     */
    public function parse(string $html, string $type): array
    {
        $crawler = new Crawler($html);
        $facets = $this->extractFacetCards($crawler, $type);

        // Fall back to the search page ranking structure: should Shodan ever
        // unify its two templates, this keeps reading.
        return $facets !== [] ? $facets : $this->facetListsIn($crawler, $type);
    }

    /**
     * Reads the flat card layout specific to this page.
     *
     * @return list<array{type: string, label: string, filter: ?string, count: int, position: int}>
     */
    private function extractFacetCards(Crawler $crawler, string $type): array
    {
        $facets = [];
        $position = 0;

        $crawler->filter('.facets-card .name')->each(
            function (Crawler $name) use (&$facets, &$position, $type): void {
                $link = $name->filter('a');

                if ($link->count() === 0) {
                    return;
                }

                // The count is not a child of the name but its sibling: the
                // first .value that follows it inside the card.
                $value = $name->nextAll()->filter('.value');

                if ($value->count() === 0) {
                    return;
                }

                $facets[] = [
                    'type' => $type,
                    'label' => trim($link->first()->text()),
                    'filter' => $this->filterTokenFrom($link->first()),
                    'count' => $this->toInt($value->first()->text()),
                    'position' => $position++,
                ];
            }
        );

        return $facets;
    }
}
