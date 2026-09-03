<?php

namespace Tests\Feature;

use App\Exceptions\ScrapingException;
use App\Models\OutboundRequest;
use App\Services\Shodan\ShodanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le journal de conformite.
 *
 * What is at stake: the assignment asks us to hold a delay between requests and
 * to know what robots.txt allows. These tests verify that we PROVE it, i.e.
 * that a verifiable trace remains, rather than merely asserting it.
 */
class ComplianceJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
    }

    #[Test]
    public function une_requete_reussie_laisse_une_trace(): void
    {
        Http::fake(['*' => Http::response('<html></html>')]);

        app(ShodanClient::class)->get('/search', ['query' => 'country:"SE"']);

        $entry = OutboundRequest::sole();

        $this->assertSame('/search', $entry->path);
        $this->assertSame('country:"SE"', $entry->query);
        $this->assertSame(200, $entry->status);
        $this->assertSame(OutboundRequest::OUTCOME_SENT, $entry->outcome);
    }

    #[Test]
    public function une_requete_refusee_par_robots_est_journalisee_bien_quaucune_ne_parte(): void
    {
        // The journal's most important case: a blocked request is the evidence
        // that the guard bites. No trace here would be indistinguishable from
        // having no guard at all.
        try {
            app(ShodanClient::class)->get('/domain/example.com');
            $this->fail('Le chemin interdit aurait du etre refuse.');
        } catch (ScrapingException) {
            // attendu
        }

        $entry = OutboundRequest::sole();

        $this->assertSame(OutboundRequest::OUTCOME_BLOCKED_BY_ROBOTS, $entry->outcome);
        $this->assertTrue($entry->wasBlocked());
        $this->assertNull($entry->status);
        $this->assertStringContainsString('/domain/', $entry->note);

        Http::assertNothingSent();
    }

    #[Test]
    public function une_erreur_serveur_est_journalisee_avec_son_code(): void
    {
        Http::fake(['*' => Http::response('indisponible', 503)]);
        config()->set('geoscan.retries', 0);

        try {
            app(ShodanClient::class)->get('/search', ['query' => 'test']);
        } catch (ScrapingException) {
            // attendu
        }

        $entry = OutboundRequest::sole();

        $this->assertSame(OutboundRequest::OUTCOME_FAILED, $entry->outcome);
        $this->assertSame(503, $entry->status);
    }

    #[Test]
    public function le_delai_reellement_observe_est_consigne(): void
    {
        // A declared delay is worth nothing: it is the time actually waited
        // before the second request that answers to "Crawl-delay: 10".
        config()->set('geoscan.request_delay', 1);
        Http::fake(['*' => Http::response('<html></html>')]);

        $client = app(ShodanClient::class);
        $client->get('/search', ['query' => 'premiere']);
        $client->get('/search', ['query' => 'seconde']);

        $entries = OutboundRequest::orderBy('id')->get();

        // La premiere n'attend personne ; la seconde a du patienter.
        $this->assertSame(0.0, $entries[0]->waited_seconds);
        $this->assertGreaterThan(0, $entries[1]->waited_seconds);
    }

    #[Test]
    public function le_journal_ne_conserve_ni_le_contenu_ni_les_cookies(): void
    {
        Http::fake(['*' => Http::response('<html>un secret dans la page</html>')]);

        app(ShodanClient::class)->get('/search', ['query' => 'test']);

        $stored = OutboundRequest::sole()->toArray();

        // A compliance journal must not become a second copy of the data, and
        // even less a store of secrets.
        $this->assertStringNotContainsString('un secret', json_encode($stored));
        $this->assertArrayNotHasKey('cookies', $stored);
    }

    #[Test]
    public function la_page_du_journal_est_publique_et_ne_sort_pas_sur_le_reseau(): void
    {
        // Evidence of responsible crawling that only its author can read does
        // not prove very much.
        $this->get(route('journal.index'))
            ->assertOk()
            ->assertSee('Crawl-delay', escape: false);

        Http::assertNothingSent();
    }

    #[Test]
    public function la_page_affiche_le_delai_le_plus_court_et_non_une_moyenne(): void
    {
        // An average would hide a burst sitting between two long pauses.
        foreach ([12.0, 0.4, 30.0] as $waited) {
            OutboundRequest::create([
                'service' => 'shodan', 'path' => '/search', 'query' => 'x',
                'status' => 200, 'outcome' => OutboundRequest::OUTCOME_SENT,
                'waited_seconds' => $waited, 'authenticated' => true, 'sent_at' => now(),
            ]);
        }

        $this->get(route('journal.index'))->assertSee('0,40 s');
    }
}
