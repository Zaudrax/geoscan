<?php

namespace Tests\Unit\Parsers;

use App\Services\Shodan\Parsers\FacetPageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Fixture;

/**
 * Parsing the "More..." page, tested against a local copy of a real
 * page /search/facet.
 *
 * This page is the only way to reach a ranking's distribution tail: without it,
 * the enumeration is capped at the 5 values shown on the
 * page de recherche.
 */
class FacetPageParserTest extends TestCase
{
    private FacetPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FacetPageParser;
    }

    #[Test]
    public function il_extrait_toutes_les_valeurs_de_la_facette(): void
    {
        $facets = $this->parser->parse(Fixture::facetPage(), 'product');

        // The search page showed only 5: the last 3 were out of the
        // enumeration's reach.
        $this->assertCount(8, $facets);
        $this->assertSame(
            ['Apache httpd', 'nginx', 'Hikvision IP Camera', 'Dahua BCS-XVR1601-IV',
                'Dahua-based DVR', 'LiteSpeed httpd', 'Microsoft IIS httpd', 'OpenResty'],
            array_column($facets, 'label'),
        );
    }

    #[Test]
    public function il_associe_a_chaque_valeur_son_compteur_et_non_celui_du_voisin(): void
    {
        // Name and count are not nested but juxtaposed: nothing structurally
        // prevents the reading from slipping by one.
        $counts = array_column($this->parser->parse(Fixture::facetPage(), 'product'), 'count', 'label');

        $this->assertSame(9, $counts['Apache httpd']);
        $this->assertSame(8, $counts['nginx']);
        $this->assertSame(7, $counts['Hikvision IP Camera']);
        $this->assertSame(1, $counts['OpenResty']);
    }

    #[Test]
    public function il_preleve_le_token_de_filtre_pret_a_recoller(): void
    {
        $filters = array_column($this->parser->parse(Fixture::facetPage(), 'product'), 'filter', 'label');

        $this->assertSame('product:"nginx"', $filters['nginx']);
        $this->assertSame('product:"Apache httpd"', $filters['Apache httpd']);
        $this->assertSame('product:"Dahua BCS-XVR1601-IV"', $filters['Dahua BCS-XVR1601-IV']);
    }

    #[Test]
    public function il_impose_le_type_demande_par_l_appelant(): void
    {
        // Contrairement a la page de recherche, aucun <h6> ne dit de quelle
        // facet it is: only the caller knows.
        $facets = $this->parser->parse(Fixture::facetPage(), 'os');

        $this->assertSame(['os'], array_values(array_unique(array_column($facets, 'type'))));
    }

    #[Test]
    public function il_sait_encore_lire_la_structure_des_classements_de_recherche(): void
    {
        // Defensive fallback: should Shodan ever unify its two templates.
        $facets = $this->parser->parse(
            '<h6>Top Ports</h6><ul class="facet-list">'
                .'<li><a href="/search?query=base+port%3A8080">8080</a><span>42</span></li>'
                .'</ul>',
            'port',
        );

        $this->assertSame([['type' => 'port', 'label' => '8080', 'filter' => 'port:8080', 'count' => 42, 'position' => 0]], $facets);
    }

    #[Test]
    public function une_page_vide_ne_produit_aucune_valeur(): void
    {
        $this->assertSame([], $this->parser->parse('<html><body></body></html>', 'port'));
    }
}
