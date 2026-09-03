<?php

namespace Tests\Unit\Parsers;

use App\Services\Shodan\Parsers\HostPageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Fixture;

/** Parsing the host page, against a local copy of a real page. */
class HostPageParserTest extends TestCase
{
    private HostPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new HostPageParser;
    }

    #[Test]
    public function il_extrait_les_informations_generales(): void
    {
        $result = $this->parser->parse(Fixture::hostPage());

        $this->assertSame('United States', $result['country']);
        $this->assertSame('Mountain View', $result['city']);
        $this->assertSame('Google LLC', $result['organization']);
        $this->assertSame('Google LLC', $result['isp']);
        $this->assertSame('AS15169', $result['asn']);
    }

    #[Test]
    public function il_extrait_les_noms_dhote_et_les_domaines(): void
    {
        $result = $this->parser->parse(Fixture::hostPage());

        $this->assertSame(['dns.google'], $result['hostnames']);
        $this->assertSame(['dns.google'], $result['domains']);
    }

    #[Test]
    public function il_extrait_les_ports_ouverts_en_entiers(): void
    {
        $result = $this->parser->parse(Fixture::hostPage());

        $this->assertSame([53, 443], $result['open_ports']);
        $this->assertContainsOnlyInt($result['open_ports']);
    }

    #[Test]
    public function il_extrait_les_technologies_web_avec_leur_categorie(): void
    {
        $result = $this->parser->parse(Fixture::hostPage());

        $this->assertContains(
            ['category' => 'Security', 'name' => 'HSTS'],
            $result['web_technologies']
        );
        $this->assertContains(
            ['category' => 'Miscellaneous', 'name' => 'HTTP/3'],
            $result['web_technologies']
        );
    }

    #[Test]
    public function il_extrait_la_date_de_derniere_observation_shodan(): void
    {
        $result = $this->parser->parse(Fixture::hostPage());

        $this->assertSame('2026-08-31', $result['shodan_last_seen']);
    }

    #[Test]
    public function il_separe_les_domaines_listes_dans_des_balises_distinctes(): void
    {
        $result = $this->parser->parse(Fixture::hostPageWithMultipleValues());

        // Sans traitement par balise, text() renverrait un seul bloc colle :
        // "harvard.edukaltura.comone.one".
        $this->assertSame(
            ['harvard.edu', 'kaltura.com', 'one.one'],
            $result['domains']
        );
    }

    #[Test]
    public function il_separe_les_noms_dhote_listes_dans_des_balises_distinctes(): void
    {
        $result = $this->parser->parse(Fixture::hostPageWithMultipleValues());

        // Le <b> n'entoure que le domaine : "wireless.med.<b>harvard.edu</b>".
        // The full hostname must be reconstructed, not reduced to its domain.
        $this->assertSame(
            [
                'lifelabtenant.wireless.med.harvard.edu',
                'rest-vpn-3056-nl.ott.kaltura.com',
                'one.one.one.one',
            ],
            $result['hostnames']
        );
    }

    #[Test]
    public function il_renvoie_des_valeurs_vides_sur_une_page_inconnue(): void
    {
        $result = $this->parser->parse('<html><body></body></html>');

        $this->assertNull($result['country']);
        $this->assertSame([], $result['open_ports']);
        $this->assertSame([], $result['hostnames']);
        $this->assertNull($result['shodan_last_seen']);
    }

    #[Test]
    public function il_lit_les_failles_connues_dans_le_script_inline(): void
    {
        $data = (new HostPageParser)->parse(Fixture::hostPageWithVulnerabilities());

        $this->assertSame(3, $data['vulnerability_count']);

        // Sorted by descending CVSS: this is the display order, and the one that
        // decides which survive the cap.
        $this->assertSame(
            ['CVE-2021-44228', 'CVE-2022-1609', 'CVE-2019-0211'],
            array_column($data['vulnerabilities'], 'id'),
        );
        $this->assertSame(10.0, $data['vulnerabilities'][0]['cvss']);
        $this->assertStringContainsString('Log4Shell', $data['vulnerabilities'][0]['summary']);
    }

    #[Test]
    public function une_fiche_sans_faille_ne_renvoie_rien(): void
    {
        $data = (new HostPageParser)->parse(Fixture::hostPage());

        $this->assertSame([], $data['vulnerabilities']);
        $this->assertSame(0, $data['vulnerability_count']);
    }

    #[Test]
    public function la_liste_stockee_est_plafonnee_mais_le_compte_reste_exact(): void
    {
        // A decoy can announce more than a thousand flaws: we keep only the most
        // severe, without ever lying about the total.
        $many = [];
        foreach (range(1, 12) as $index) {
            $many['CVE-2024-'.(1000 + $index)] = ['cvss' => $index / 2, 'summary' => 'faille '.$index];
        }

        $data = (new HostPageParser(maxVulnerabilities: 2))->parse(
            Fixture::hostPageWithVulnerabilities($many)
        );

        $this->assertCount(2, $data['vulnerabilities']);
        $this->assertSame(12, $data['vulnerability_count']);
        $this->assertSame(6.0, $data['vulnerabilities'][0]['cvss']);   // la plus grave
    }
}
