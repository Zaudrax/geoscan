<?php

namespace Tests\Feature;

use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application's access policy.
 *
 * There are no accounts: GeoScan is a single operator tool. What still needs
 * protecting is the QUOTA -- the scraping routes send requests signed with the
 * Shodan cookie kept in .env -- and the rate limits are what protect it.
 */
class AccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test's safety net: any unfaked outbound call fails the test.
        Http::preventStrayRequests();
    }

    #[Test]
    public function toutes_les_pages_sont_accessibles_sans_compte(): void
    {
        $scan = Scan::factory()->create();

        $this->get(route('scans.index'))->assertOk();
        $this->get(route('scans.show', $scan))->assertOk();
        $this->get(route('scans.create'))->assertOk();
        $this->get(route('searches.index'))->assertOk();
        $this->get(route('searches.create'))->assertOk();
        $this->get(route('hosts.create'))->assertOk();
        $this->get(route('watches.index'))->assertOk();
        $this->get(route('journal.index'))->assertOk();

        // Reading the archive and displaying the forms scrapes nothing.
        Http::assertNothingSent();
    }

    #[Test]
    public function le_lancement_de_scan_est_limite_a_trois_par_minute(): void
    {
        // A payload that fails validation: it exercises the throttle without
        // queueing a scan, which is what the limit is really there to cap.
        $payload = ['country_code' => 'pays inexistant'];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('scans.store'), $payload)->assertStatus(302);
        }

        // The fourth submission within the minute is refused: a stray refresh
        // cannot chain enumerations worth 30 outbound requests each.
        $this->post(route('scans.store'), $payload)->assertStatus(429);

        $this->assertDatabaseCount('scans', 0);
        Http::assertNothingSent();
    }
}
