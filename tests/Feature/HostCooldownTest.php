<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\HostSnapshot;
use App\Services\Shodan\HostScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

/**
 * The guard from step 9: two visits close together create a single snapshot;
 * once the delay has passed, a new visit creates a second one.
 */
class HostCooldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
        config()->set('geoscan.host_cooldown', 300);
    }

    /**
     * Http stubs are set per test: Laravel keeps the first matching stub, so a
     * global fake in setUp() would make it impossible to simulate a page that
     * changes between two visits.
     */
    private function fakeHostPage(?string $html = null): void
    {
        Http::fake(['*' => Http::response($html ?? Fixture::hostPage())]);
    }

    #[Test]
    public function une_premiere_visite_scrape_et_cree_un_instantane(): void
    {
        $this->fakeHostPage();

        $result = app(HostScraper::class)->fetch('8.8.8.8');

        $this->assertFalse($result->reusedExisting);
        $this->assertSame('Google LLC', $result->snapshot->organization);
        $this->assertSame([53, 443], $result->snapshot->open_ports);
        $this->assertSame(1, HostSnapshot::count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function deux_visites_de_suite_ne_creent_quun_seul_instantane(): void
    {
        $this->fakeHostPage();

        app(HostScraper::class)->fetch('8.8.8.8');
        $second = app(HostScraper::class)->fetch('8.8.8.8');

        $this->assertTrue($second->reusedExisting);
        $this->assertSame(1, HostSnapshot::count());
        Http::assertSentCount(1);            // la 2e visite n'a rien envoye
    }

    #[Test]
    public function une_visite_apres_expiration_du_delai_cree_un_second_instantane(): void
    {
        $this->fakeHostPage();

        app(HostScraper::class)->fetch('8.8.8.8');

        $this->travel(301)->seconds();       // le cooldown est expire

        $second = app(HostScraper::class)->fetch('8.8.8.8');

        $this->assertFalse($second->reusedExisting);
        $this->assertSame(2, HostSnapshot::count());
        Http::assertSentCount(2);
    }

    #[Test]
    public function un_hote_nest_jamais_enregistre_deux_fois(): void
    {
        $this->fakeHostPage();

        app(HostScraper::class)->fetch('8.8.8.8');
        $this->travel(301)->seconds();
        app(HostScraper::class)->fetch('8.8.8.8');

        $this->assertSame(1, Host::where('ip', '8.8.8.8')->count());
        $this->assertSame(2, Host::first()->snapshots()->count());
    }

    #[Test]
    public function un_changement_de_noms_dhote_est_signale_dans_la_timeline(): void
    {
        // Actually observed on 1.1.1.1: between two visits five hours apart,
        // Shodan returned different hostnames and domains while the
        // organisation stayed the same.
        Http::fakeSequence()
            ->push(Fixture::hostPageWithMultipleValues())
            ->push(str_replace(
                ['harvard.edu', 'kaltura.com'],
                ['schoolloop.com', 'example.org'],
                Fixture::hostPageWithMultipleValues()
            ));

        $first = app(HostScraper::class)->fetch('1.1.1.1')->snapshot;
        $this->travel(301)->seconds();
        $second = app(HostScraper::class)->fetch('1.1.1.1')->snapshot;

        $changes = collect($second->changesSince($first))->keyBy('field');

        $this->assertTrue($changes->has('hostnames'));
        $this->assertTrue($changes->has('domains'));
        $this->assertFalse($changes->has('organization'));   // inchangee
    }

    #[Test]
    public function un_port_qui_souvre_est_nomme_dans_le_diff(): void
    {
        // The signal this whole page exists to surface: between two visits, an
        // RDP service was exposed.
        $host = Host::create(['ip' => '203.0.113.7']);

        $before = $host->snapshots()->create([
            'fetched_at' => now()->subDay(),
            'open_ports' => [80, 443],
        ]);

        $after = $host->snapshots()->create([
            'fetched_at' => now(),
            'open_ports' => [80, 443, 3389],
        ]);

        $this->assertSame(
            [
                'field' => 'open_ports',
                'label' => 'ports ouverts',
                'kind' => 'list',
                'added' => ['3389'],
                'removed' => [],
            ],
            collect($after->changesSince($before))->firstWhere('field', 'open_ports'),
        );
    }

    #[Test]
    public function un_port_qui_se_ferme_est_distingue_dun_port_qui_souvre(): void
    {
        $host = Host::create(['ip' => '203.0.113.8']);

        $before = $host->snapshots()->create([
            'fetched_at' => now()->subDay(),
            'open_ports' => [22, 80],
        ]);

        $after = $host->snapshots()->create([
            'fetched_at' => now(),
            'open_ports' => [80, 8080],
        ]);

        $change = collect($after->changesSince($before))->firstWhere('field', 'open_ports');

        $this->assertSame(['8080'], $change['added']);
        $this->assertSame(['22'], $change['removed']);
    }

    #[Test]
    public function une_technologie_web_est_comparee_par_son_nom(): void
    {
        // Technologies are {name, category} objects: comparing them bluntly
        // would turn a mere recategorisation into a change.
        $host = Host::create(['ip' => '203.0.113.9']);

        $before = $host->snapshots()->create([
            'fetched_at' => now()->subDay(),
            'web_technologies' => [['name' => 'nginx', 'category' => 'Web Servers']],
        ]);

        $after = $host->snapshots()->create([
            'fetched_at' => now(),
            'web_technologies' => [
                ['name' => 'nginx', 'category' => 'Web Servers'],
                ['name' => 'WordPress', 'category' => 'CMS'],
            ],
        ]);

        $change = collect($after->changesSince($before))->firstWhere('field', 'web_technologies');

        $this->assertSame(['WordPress'], $change['added']);
        $this->assertSame([], $change['removed']);
    }

    #[Test]
    public function le_premier_instantane_na_rien_a_comparer(): void
    {
        $host = Host::create(['ip' => '203.0.113.10']);

        $first = $host->snapshots()->create([
            'fetched_at' => now(),
            'open_ports' => [80],
        ]);

        $this->assertSame([], $first->changesSince(null));
    }

    #[Test]
    public function un_simple_changement_dordre_dans_une_liste_nest_pas_un_changement(): void
    {
        $host = Host::create(['ip' => '203.0.113.1']);

        $before = $host->snapshots()->create([
            'fetched_at' => now()->subHour(),
            'open_ports' => [80, 443, 8080],
            'hostnames' => ['a.test', 'b.test'],
        ]);

        $after = $host->snapshots()->create([
            'fetched_at' => now(),
            'open_ports' => [8080, 80, 443],       // memes ports, autre ordre
            'hostnames' => ['b.test', 'a.test'],
        ]);

        $this->assertSame([], $after->changesSince($before));
    }

    #[Test]
    public function les_instantanes_precedents_ne_sont_jamais_modifies(): void
    {
        // The page is served as-is first, then with a different organisation:
        // we are simulating a host that changed hands.
        Http::fakeSequence()
            ->push(Fixture::hostPage())
            ->push(str_replace('Google LLC', 'Une Autre Organisation', Fixture::hostPage()));

        $first = app(HostScraper::class)->fetch('8.8.8.8')->snapshot;

        $this->travel(301)->seconds();

        $second = app(HostScraper::class)->fetch('8.8.8.8')->snapshot;

        $this->assertSame('Google LLC', $first->fresh()->organization);
        $this->assertSame('Une Autre Organisation', $second->organization);
        // The diff names the old and the new value, not just the field that
        // moved. (The ISP moves too: the page carried the same company name in
        // both places.)
        $this->assertSame(
            [
                'field' => 'organization',
                'label' => 'organisation',
                'kind' => 'scalar',
                'from' => 'Google LLC',
                'to' => 'Une Autre Organisation',
            ],
            collect($second->changesSince($first))->firstWhere('field', 'organization'),
        );
    }
}
