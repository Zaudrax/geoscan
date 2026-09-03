<?php

namespace Tests\Feature;

use App\Jobs\RunScan;
use App\Models\GeoPoint;
use App\Models\Scan;
use App\Models\ScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scan form, and above all the reading view: filters computed against our
 * own database, never going back out to Shodan.
 */
class ScanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('geoscan.geocoding.enabled', false);
    }

    #[Test]
    public function le_formulaire_propose_la_liste_des_pays(): void
    {
        Queue::fake();

        $this->get(route('scans.create'))
            ->assertOk()
            ->assertSee('Pologne (PL)')
            ->assertSee('Nouveau scan par pays');
    }

    #[Test]
    public function il_previent_quand_aucun_compte_shodan_n_est_configure(): void
    {
        config()->set('geoscan.login.enabled', false);

        $this->get(route('scans.create'))
            ->assertOk()
            ->assertSee('Aucun compte Shodan configuré');
    }

    #[Test]
    public function soumettre_le_formulaire_met_le_scan_en_file_sans_rien_scraper(): void
    {
        Queue::fake();

        $response = $this->post(route('scans.store'), [
            'country_code' => 'PL',
            'observed_on' => '2026-09-01',
            'observed_hour' => 9,
            'observed_minute' => 13,
            'observed_second' => 3,
        ]);

        $scan = Scan::sole();

        $response->assertRedirect(route('scans.show', $scan));
        $this->assertSame('country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT', $scan->base_query);
        $this->assertSame(Scan::STATUS_RUNNING, $scan->status);

        Queue::assertPushed(RunScan::class);
        Http::assertNothingSent();     // le travail appartient au job, pas a la requete web
    }

    #[Test]
    public function un_terme_de_banniere_lance_un_scan_sans_horodatage(): void
    {
        Queue::fake();

        $this->post(route('scans.store'), [
            'country_code' => 'SE',
            'base_term' => 'Server: yawcam',
        ])->assertSessionHasNoErrors();

        $scan = Scan::sole();

        $this->assertSame('Server: yawcam', $scan->base_term);
        $this->assertNull($scan->observed_on);
        $this->assertSame('country:"SE" Server: yawcam', $scan->base_query);

        Queue::assertPushed(RunScan::class);
    }

    #[Test]
    public function sans_terme_ni_horodatage_le_formulaire_est_refuse(): void
    {
        Queue::fake();

        $this->post(route('scans.store'), ['country_code' => 'SE'])
            ->assertSessionHasErrors(['observed_on', 'observed_hour', 'observed_minute']);

        $this->assertDatabaseCount('scans', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function sans_seconde_la_requete_affichee_montre_le_motif_balaye(): void
    {
        Queue::fake();

        $this->post(route('scans.store'), [
            'country_code' => 'PL',
            'observed_on' => '2026-09-01',
            'observed_hour' => 9,
            'observed_minute' => 13,
        ])->assertSessionHasNoErrors();

        $scan = Scan::sole();

        $this->assertNull($scan->observed_second);
        $this->assertSame('country:"PL" Date: Tue, 01 Sep 2026 09:13:xx GMT', $scan->base_query);
    }

    #[Test]
    public function il_refuse_un_pays_inconnu_et_une_heure_hors_bornes(): void
    {
        Queue::fake();

        $this->post(route('scans.store'), [
            'country_code' => 'ZZ',
            'observed_on' => '2026-09-01',
            'observed_hour' => 42,
            'observed_minute' => 13,
        ])->assertSessionHasErrors(['country_code', 'observed_hour']);

        $this->assertDatabaseCount('scans', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function la_vue_d_un_scan_ne_declenche_aucune_requete_sortante(): void
    {
        // Same rule as the search history: reading an archive is not redoing it.
        $scan = Scan::factory()->create();
        ScanResult::factory()->count(3)->create(['scan_id' => $scan->id]);

        $this->get(route('scans.show', $scan))->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function les_filtres_de_gauche_sont_calcules_sur_nos_propres_lignes(): void
    {
        $scan = Scan::factory()->create();

        ScanResult::factory()->count(3)->create(['scan_id' => $scan->id, 'port' => 80, 'city' => 'Warsaw']);
        ScanResult::factory()->create(['scan_id' => $scan->id, 'port' => 443, 'city' => 'Krakow']);

        $response = $this->get(route('scans.show', $scan));

        $facets = $response->viewData('facets');

        $this->assertSame(
            [['value' => '80', 'label' => '80', 'count' => 3], ['value' => '443', 'label' => '443', 'count' => 1]],
            $facets['port'],
        );
        $this->assertSame('Warsaw', $facets['city'][0]['value']);
    }

    #[Test]
    public function un_filtre_de_port_restreint_la_liste_des_resultats(): void
    {
        $scan = Scan::factory()->create();

        ScanResult::factory()->count(3)->create(['scan_id' => $scan->id, 'port' => 80]);
        ScanResult::factory()->create(['scan_id' => $scan->id, 'port' => 443, 'ip' => '10.9.9.9']);

        $response = $this->get(route('scans.show', ['scan' => $scan, 'port' => 443]));

        $this->assertCount(1, $response->viewData('results'));
        $response->assertSee('10.9.9.9');
    }

    #[Test]
    public function les_filtres_horaires_descendent_jusqu_a_la_seconde(): void
    {
        $scan = Scan::factory()->create();

        ScanResult::factory()->create([
            'scan_id' => $scan->id,
            'ip' => '10.1.1.1',
            ...ScanResult::timeParts(Carbon::parse('2026-09-01 09:13:03')),
        ]);
        ScanResult::factory()->create([
            'scan_id' => $scan->id,
            'ip' => '10.2.2.2',
            ...ScanResult::timeParts(Carbon::parse('2026-09-01 09:13:47')),
        ]);
        ScanResult::factory()->create([
            'scan_id' => $scan->id,
            'ip' => '10.3.3.3',
            ...ScanResult::timeParts(Carbon::parse('2026-09-02 14:13:03')),
        ]);

        $byDate = $this->get(route('scans.show', ['scan' => $scan, 'date' => '2026-09-01']));
        $this->assertCount(2, $byDate->viewData('results'));

        $byHour = $this->get(route('scans.show', ['scan' => $scan, 'hour' => 14]));
        $this->assertCount(1, $byHour->viewData('results'));

        $bySecond = $this->get(route('scans.show', ['scan' => $scan, 'second' => 3]));
        $this->assertCount(2, $bySecond->viewData('results'));

        $combined = $this->get(route('scans.show', ['scan' => $scan, 'hour' => 9, 'second' => 3]));
        $this->assertCount(1, $combined->viewData('results'));
        $combined->assertSee('10.1.1.1');
    }

    #[Test]
    public function un_filtre_vide_ne_vide_pas_la_liste(): void
    {
        // A form's query string sends back empty parameters: taking them at face
        // value would filter on the empty string.
        $scan = Scan::factory()->create();
        ScanResult::factory()->count(2)->create(['scan_id' => $scan->id]);

        $response = $this->get(route('scans.show', ['scan' => $scan, 'port' => '', 'city' => '', 'q' => '']));

        $this->assertCount(2, $response->viewData('results'));
        $this->assertSame([], $response->viewData('filters'));
    }

    #[Test]
    public function la_recherche_libre_couvre_l_ip_la_banniere_et_l_organisation(): void
    {
        $scan = Scan::factory()->create();

        ScanResult::factory()->create(['scan_id' => $scan->id, 'ip' => '10.1.1.1', 'organization' => 'Multinet24']);
        ScanResult::factory()->create(['scan_id' => $scan->id, 'ip' => '10.2.2.2', 'organization' => 'Oxylion']);

        $response = $this->get(route('scans.show', ['scan' => $scan, 'q' => 'Multinet']));

        $this->assertCount(1, $response->viewData('results'));
        $response->assertSee('10.1.1.1');
    }

    #[Test]
    public function la_carte_rend_la_molette_a_la_page_hors_survol(): void
    {
        // A map that permanently captures the wheel blocks page scrolling as
        // soon as the cursor crosses it. The enable/disable dance is therefore
        // the feature, not an implementation detail.
        $scan = Scan::factory()->create();
        ScanResult::factory()->count(2)->create(['scan_id' => $scan->id, 'city' => 'Warsaw']);

        GeoPoint::create([
            'country_code' => 'PL', 'city' => 'Warsaw',
            'latitude' => 52.23, 'longitude' => 21.01,
            'source' => GeoPoint::SOURCE_GEOCODER, 'attempts' => 1, 'resolved_at' => now(),
        ]);

        $response = $this->get(route('scans.show', $scan));

        $this->assertCount(2, $response->viewData('markers'));
        $response
            ->assertSee('scrollWheelZoom: false', escape: false)
            ->assertSee("addEventListener('mouseenter', () => map.scrollWheelZoom.enable())", escape: false)
            ->assertSee("addEventListener('mouseleave', () => map.scrollWheelZoom.disable())", escape: false);
    }

    #[Test]
    public function la_vue_annonce_le_depassement_du_plafond_de_shodan(): void
    {
        config()->set('geoscan.enumeration.page_limit', 2);
        config()->set('geoscan.enumeration.per_page', 10);

        $scan = Scan::factory()->create(['unique_hosts' => 32, 'total_reported' => 39]);
        ScanResult::factory()->count(32)->create(['scan_id' => $scan->id]);

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('plafond Shodan')
            ->assertSee('dépassé de 12');

        $this->assertTrue($scan->beatTheCeiling());
        $this->assertSame(82, (int) round($scan->coverage() * 100));
    }
}
