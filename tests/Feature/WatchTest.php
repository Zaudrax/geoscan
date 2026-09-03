<?php

namespace Tests\Feature;

use App\Console\Commands\RunDueWatches;
use App\Jobs\RunScan;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\Watch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Watches: replaying a search, and reporting only what is new.
 *
 * Nothing goes out on the wire here: the command only queues a scan, and the
 * comparison happens entirely in the database.
 */
class WatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
    }

    #[Test]
    public function une_veille_jamais_executee_est_due_immediatement(): void
    {
        Watch::factory()->create();

        $this->artisan(RunDueWatches::class)->assertSuccessful();

        Queue::assertPushed(RunScan::class, 1);
        $this->assertDatabaseCount('scans', 1);
    }

    #[Test]
    public function une_veille_qui_vient_de_tourner_nest_pas_rejouee(): void
    {
        Watch::factory()->justRan()->create(['interval_hours' => 24]);

        $this->artisan(RunDueWatches::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function une_veille_redevient_due_une_fois_son_intervalle_passe(): void
    {
        $watch = Watch::factory()->create(['interval_hours' => 6]);
        $watch->forceFill(['last_run_at' => now()->subHours(7)])->save();

        $this->artisan(RunDueWatches::class)->assertSuccessful();

        Queue::assertPushed(RunScan::class, 1);
    }

    #[Test]
    public function une_veille_suspendue_nest_jamais_rejouee(): void
    {
        Watch::factory()->inactive()->create();

        $this->artisan(RunDueWatches::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function lheure_de_passage_est_marquee_avant_lexecution(): void
    {
        // If the scan fails, the watch must not restart on every single tick of
        // the scheduler.
        $watch = Watch::factory()->create();

        $this->artisan(RunDueWatches::class)->assertSuccessful();

        $this->assertNotNull($watch->fresh()->last_run_at);
    }

    #[Test]
    public function le_premier_passage_ne_signale_aucune_nouveaute(): void
    {
        // A first scan is a baseline, not a discovery: announcing "80 new
        // services" on the first pass would be nonsense.
        $watch = Watch::factory()->create();
        $scan = $this->scanFor($watch, [['10.0.0.1', 80], ['10.0.0.2', 443]]);

        $this->assertTrue($watch->fresh()->load('scans')->newcomers()->isEmpty());
        $this->assertSame(2, $scan->results()->count());
    }

    #[Test]
    public function seuls_les_services_absents_du_passage_precedent_sont_signales(): void
    {
        $watch = Watch::factory()->create();

        $this->scanFor($watch, [['10.0.0.1', 80], ['10.0.0.2', 443]]);
        $this->scanFor($watch, [['10.0.0.1', 80], ['10.0.0.3', 3389]]);

        $newcomers = $watch->fresh()->load('scans')->newcomers();

        $this->assertCount(1, $newcomers);
        $this->assertSame('10.0.0.3', $newcomers->first()->ip);
    }

    #[Test]
    public function un_nouveau_service_sur_une_ip_deja_connue_est_une_nouveaute(): void
    {
        // The comparison is on the (IP, port) pair: a machine we already knew
        // that opens a second service is a real change to the surface.
        $watch = Watch::factory()->create();

        $this->scanFor($watch, [['10.0.0.1', 80]]);
        $this->scanFor($watch, [['10.0.0.1', 80], ['10.0.0.1', 3389]]);

        $newcomers = $watch->fresh()->load('scans')->newcomers();

        $this->assertCount(1, $newcomers);
        $this->assertSame(3389, $newcomers->first()->port);
    }

    #[Test]
    public function la_page_dune_veille_est_consultable_sans_compte(): void
    {
        $watch = Watch::factory()->create();

        $this->get(route('watches.index'))->assertOk();
        $this->get(route('watches.show', $watch))->assertOk();
    }

    #[Test]
    public function un_operateur_enregistre_une_veille_sans_declencher_de_requete(): void
    {
        $this->post(route('watches.store'), [
            'label' => 'Webcams suedoises',
            'country_code' => 'SE',
            'base_term' => '"Server: yawcam"',
            'interval_hours' => 12,
        ])->assertRedirect();

        $this->assertDatabaseHas('watches', ['label' => 'Webcams suedoises', 'interval_hours' => 12]);

        // A watch is an intention, not an action.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /**
     * One already completed watch pass, with its results.
     *
     * @param  list<array{0: string, 1: int}>  $services
     */
    private function scanFor(Watch $watch, array $services): Scan
    {
        $scan = Scan::factory()->create([
            'watch_id' => $watch->id,
            'started_at' => now(),
            'status' => Scan::STATUS_COMPLETED,
        ]);

        foreach ($services as [$ip, $port]) {
            ScanResult::factory()->create(['scan_id' => $scan->id, 'ip' => $ip, 'port' => $port]);
        }

        $this->travel(1)->minutes();

        return $scan;
    }
}
