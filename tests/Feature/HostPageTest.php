<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\Scan;
use App\Models\ScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

/** Step 10: the host record view and its timeline. */
class HostPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
    }

    #[Test]
    public function la_fiche_affiche_les_informations_les_plus_recentes(): void
    {
        Http::fake(['*' => Http::response(Fixture::hostPage())]);

        $this->get(route('hosts.show', '8.8.8.8'))
            ->assertOk()
            ->assertSee('Google LLC')
            ->assertSee('Mountain View')
            ->assertSee('AS15169')
            ->assertSee('dns.google')
            ->assertSee('HSTS')
            ->assertSee('443');
    }

    #[Test]
    public function la_ligne_du_temps_liste_tous_les_instantanes(): void
    {
        Http::fakeSequence()
            ->push(Fixture::hostPage())
            ->push(str_replace('Google LLC', 'Nouvelle Organisation', Fixture::hostPage()));

        $this->get(route('hosts.show', '8.8.8.8'));
        $this->travel(301)->seconds();
        $response = $this->get(route('hosts.show', '8.8.8.8'));

        $response->assertOk()
            ->assertSee('Ligne du temps (2 instantanés)')
            ->assertSee('Nouvelle Organisation')      // le plus recent en tete
            ->assertSee('Google LLC')                 // l'ancien, conserve
            ->assertSee('organisation');              // le champ signale comme modifie

        $this->assertSame(2, Host::first()->snapshots()->count());
    }

    #[Test]
    public function une_seconde_visite_signale_la_reutilisation_du_cooldown(): void
    {
        Http::fake(['*' => Http::response(Fixture::hostPage())]);

        $this->get(route('hosts.show', '8.8.8.8'));

        $this->get(route('hosts.show', '8.8.8.8'))
            ->assertOk()
            ->assertSee('Instantané récent réutilisé');

        Http::assertSentCount(1);
    }

    #[Test]
    public function une_ip_invalide_renvoie_404(): void
    {
        $this->get('/hotes/pas-une-ip')->assertNotFound();
        Http::assertNothingSent();
    }

    #[Test]
    public function la_fiche_reste_consultable_si_le_scraping_echoue(): void
    {
        // Retries off: this test is about the fallback when scraping genuinely
        // fails, not about retrying itself (see ShodanClientTest). Without it,
        // the single 503 would be replayed.
        config()->set('geoscan.retries', 0);

        Http::fakeSequence()
            ->push(Fixture::hostPage())
            ->pushStatus(503);

        $this->get(route('hosts.show', '8.8.8.8'));
        $this->travel(301)->seconds();

        // Scraping fails, but the snapshot already in the database still shows.
        $this->get(route('hosts.show', '8.8.8.8'))
            ->assertOk()
            ->assertSee('HTTP 503')
            ->assertSee('Google LLC');
    }

    #[Test]
    public function la_fiche_signale_quand_shodan_tient_lhote_pour_un_leurre(): void
    {
        // Tags never appear on a host page, only on search pages: without this
        // lookup a decoy would be presented as a critical risk while its data
        // is fabricated.
        Http::fake(['*' => Http::response(Fixture::hostPage())]);

        $scan = Scan::factory()->create();
        ScanResult::factory()->create([
            'scan_id' => $scan->id,
            'ip' => '8.8.8.8',
            'tags' => ['cloud', 'honeypot'],
        ]);

        $this->get(route('hosts.show', '8.8.8.8'))
            ->assertOk()
            ->assertSee('leurre', escape: false);
    }
}
