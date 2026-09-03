<?php

namespace Tests\Unit\Parsers;

use App\Exceptions\ScrapingException;
use App\Services\Shodan\Parsers\SearchPageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Fixture;

/**
 * Parsing the search page, tested against a local copy of a real page: no
 * network access is required.
 */
class SearchPageParserTest extends TestCase
{
    private SearchPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SearchPageParser;
    }

    #[Test]
    public function il_extrait_le_nombre_total_de_resultats(): void
    {
        $result = $this->parser->parse(Fixture::searchPage());

        $this->assertSame(53_611_312, $result['total_results']);
    }

    #[Test]
    public function il_extrait_les_cinq_groupes_de_classement(): void
    {
        $result = $this->parser->parse(Fixture::searchPage());

        $types = array_unique(array_column($result['facets'], 'type'));
        sort($types);

        $this->assertSame(['country', 'org', 'os', 'port', 'product'], $types);
    }

    #[Test]
    public function chaque_groupe_contient_cinq_entrees_ordonnees(): void
    {
        $result = $this->parser->parse(Fixture::searchPage());

        $ports = array_values(array_filter(
            $result['facets'],
            fn (array $facet) => $facet['type'] === 'port'
        ));

        $this->assertCount(5, $ports);
        $this->assertSame('80', $ports[0]['label']);
        $this->assertSame(10_687_787, $ports[0]['count']);
        $this->assertSame(0, $ports[0]['position']);
        $this->assertSame(4, $ports[4]['position']);
    }

    #[Test]
    public function il_ignore_le_lien_more_en_fin_de_liste(): void
    {
        $result = $this->parser->parse(Fixture::searchPage());

        $labels = array_column($result['facets'], 'label');

        $this->assertNotContains('More...', $labels);
        $this->assertCount(25, $result['facets']); // 5 groupes x 5 entrees
    }

    #[Test]
    public function il_supporte_une_page_sans_aucun_classement(): void
    {
        $result = $this->parser->parse(Fixture::searchPageWithoutFacets());

        $this->assertSame(1234, $result['total_results']);
        $this->assertSame([], $result['facets']);
    }

    #[Test]
    public function il_leve_une_exception_si_la_structure_a_change(): void
    {
        $this->expectException(ScrapingException::class);

        $this->parser->parse('<html><body><p>Page totalement differente</p></body></html>');
    }

    #[Test]
    public function il_preleve_le_token_de_filtre_dans_le_lien_de_chaque_classement(): void
    {
        // It is this token, not the displayed label, that we append to the query
        // to descend one level in the split.
        $result = $this->parser->parse(Fixture::searchPage());

        $filters = array_column($result['facets'], 'filter', 'label');

        $this->assertSame('port:80', $filters['80']);
        $this->assertSame('country:"US"', $filters['United States']);

        // The trap: the comma in the displayed name becomes a space in the
        // filter, and the whole thing gets quoted. Rebuilding this token from
        // the label would only ever yield org:"Meteverse Limited.".
        $this->assertSame('org:"Meteverse Limited."', $filters['Meteverse Limited.']);
    }

    #[Test]
    public function il_extrait_les_resultats_individuels(): void
    {
        $result = $this->parser->parse(Fixture::searchPage());

        $this->assertCount(10, $result['results']);

        $first = $result['results'][0];

        $this->assertSame('202.182.118.34', $first['ip']);
        $this->assertSame(5263, $first['port']);
        $this->assertSame('JP', $first['country_code']);
        $this->assertSame('Japan', $first['country']);
        $this->assertSame('Ōi', $first['city']);
        $this->assertSame('The Constant Company, LLC', $first['organization']);
        $this->assertSame(['202.182.118.34.vultrusercontent.com'], $first['hostnames']);
        $this->assertSame(['Nginx'], $first['technologies']);
        $this->assertSame(['cloud', 'self-signed'], $first['tags']);
    }

    #[Test]
    public function il_horodate_chaque_resultat_a_la_seconde(): void
    {
        // The second is the finest splitting dimension we have: losing it would
        // mean losing the most discriminating filter.
        $result = $this->parser->parse(Fixture::searchPage());

        $this->assertSame(
            '2026-08-31 07:47:03',
            $result['results'][0]['observed_at']->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function il_conserve_les_retours_ligne_de_la_banniere(): void
    {
        // The banner is a raw HTTP header: flattened onto one line it becomes
        // unreadable and its "Date:" line undetectable.
        $result = $this->parser->parse(Fixture::searchPage());

        $this->assertStringContainsString(
            "HTTP/1.1 404 Not Found\nServer: nginx",
            $result['results'][0]['banner'],
        );
    }

    #[Test]
    public function il_ne_retient_pas_l_ip_comme_nom_d_hote(): void
    {
        // Shodan repeats the IP as the first <li class="hostnames">: that is not
        // a hostname, it is a duplicate.
        $result = $this->parser->parse(Fixture::searchPage());

        foreach ($result['results'] as $entry) {
            $this->assertNotContains($entry['ip'], $entry['hostnames']);
        }
    }

    #[Test]
    public function il_signale_l_existence_d_une_page_suivante(): void
    {
        $this->assertSame(2, $this->parser->parse(Fixture::searchPage())['next_page']);
        $this->assertNull($this->parser->parse(Fixture::searchResultsPage(3))['next_page']);
    }
}
