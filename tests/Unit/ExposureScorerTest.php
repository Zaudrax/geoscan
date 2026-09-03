<?php

namespace Tests\Unit;

use App\Models\HostSnapshot;
use App\Models\ScanResult;
use App\Services\Exposure\ExposureScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La lecture d'exposition.
 *
 * No database, no network: the service only reads model attributes, which can
 * therefore be filled in by hand without ever persisting them.
 */
class ExposureScorerTest extends TestCase
{
    private ExposureScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ExposureScorer;
    }

    #[Test]
    public function un_port_dadministration_expose_est_critique(): void
    {
        $report = $this->scorer->forScanResult(
            new ScanResult(['port' => 3389, 'tags' => [], 'banner' => null])
        );

        $this->assertSame('critique', $report->level());
        $this->assertStringContainsString('RDP', $report->worst()->title);

        // The WHY is the heart of a finding: a level with no justification
        // teaches nothing and cannot be argued with.
        $this->assertNotEmpty($report->worst()->why);
    }

    #[Test]
    public function un_port_web_ordinaire_ne_declenche_rien(): void
    {
        $report = $this->scorer->forScanResult(
            new ScanResult(['port' => 443, 'tags' => [], 'banner' => null])
        );

        $this->assertTrue($report->isEmpty());
        $this->assertFalse($report->isNotable());
        $this->assertSame('Rien a signaler', $report->levelLabel());
    }

    #[Test]
    public function le_niveau_retenu_est_le_pire_constat_jamais_une_somme(): void
    {
        // Three moderate findings must not manufacture an emergency.
        $report = $this->scorer->forSnapshot(
            new HostSnapshot(['open_ports' => [22, 161], 'vulnerability_count' => 0])
        );

        $this->assertSame('modere', $report->level());
        $this->assertSame(2, $report->count());
    }

    #[Test]
    public function une_banniere_qui_annonce_sa_version_est_signalee(): void
    {
        $report = $this->scorer->forScanResult(new ScanResult([
            'port' => 8080,
            'tags' => [],
            'banner' => "HTTP/1.1 200 OK\r\nServer: Apache/2.4.51 (Amazon)\r\n",
        ]));

        $version = collect($report->sorted())->firstWhere('title', 'Version de serveur divulguée');

        $this->assertNotNull($version);
        $this->assertSame('Apache/2.4.51', $version->detail);
        $this->assertSame('modere', $report->level());
    }

    #[Test]
    public function repondre_200_ne_suffit_pas_a_faire_un_constat_notable(): void
    {
        // Measured 2026-09-03: a rule flagging every 200 response lit up on 180
        // of the 181 services in a real scan. Answering 200 is the normal
        // behaviour of the web; it stays information, not an alert.
        $report = $this->scorer->forScanResult(new ScanResult([
            'port' => 8080,
            'tags' => [],
            'banner' => "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n",
        ]));

        $this->assertSame('info', $report->level());
        $this->assertFalse($report->isNotable());
    }

    #[Test]
    public function un_serveur_dequipement_embarque_est_lui_notable(): void
    {
        // The signal comes from the NATURE of the server, not its status code.
        $report = $this->scorer->forScanResult(new ScanResult([
            'port' => 8080,
            'tags' => [],
            'banner' => "HTTP/1.1 200 OK\r\nServer: yawcam\r\n",
        ]));

        $this->assertSame('eleve', $report->level());
        $this->assertSame('Équipement embarqué joignable', $report->worst()->title);
    }

    #[Test]
    public function une_etiquette_leurre_est_signalee_sans_dramatiser(): void
    {
        // A honeypot is not a risk: it is a warning that the data on display is
        // most likely fabricated.
        $report = $this->scorer->forScanResult(
            new ScanResult(['port' => 443, 'tags' => ['cloud', 'honeypot'], 'banner' => null])
        );

        $this->assertSame('info', $report->level());
        $this->assertFalse($report->isNotable());
    }

    #[Test]
    public function le_niveau_des_failles_suit_le_cvss_le_plus_eleve(): void
    {
        $report = $this->scorer->forSnapshot(new HostSnapshot([
            'open_ports' => [],
            'vulnerability_count' => 3,
            'vulnerabilities' => [
                ['id' => 'CVE-2019-0211', 'cvss' => 7.8, 'summary' => null],
                ['id' => 'CVE-2021-44228', 'cvss' => 10.0, 'summary' => null],
                ['id' => 'CVE-2020-0001', 'cvss' => 4.2, 'summary' => null],
            ],
        ]));

        // 10.0 clears the NVD's critical threshold (9.0), and it is the most
        // severe CVE that gets named, not the first one in the list.
        $this->assertSame('critique', $report->level());
        $this->assertSame('CVE-2021-44228', $report->worst()->detail);
        $this->assertStringContainsString('10/10', $report->worst()->why);
    }

    #[Test]
    public function des_failles_sans_score_restent_moderees(): void
    {
        $report = $this->scorer->forSnapshot(new HostSnapshot([
            'open_ports' => [],
            'vulnerability_count' => 2,
            'vulnerabilities' => [
                ['id' => 'CVE-2020-1111', 'cvss' => null, 'summary' => null],
                ['id' => 'CVE-2020-2222', 'cvss' => null, 'summary' => null],
            ],
        ]));

        $this->assertSame('modere', $report->level());
        $this->assertStringContainsString('2 failles connues', $report->worst()->title);
    }
}
