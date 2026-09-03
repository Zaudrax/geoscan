<?php

namespace Tests\Feature;

use App\Exceptions\ScrapingException;
use App\Services\Shodan\ShodanClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** La politique de crawl de l'etape 5 : User-Agent, delai, robots.txt. */
class ShodanClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();     // aucune vraie requete ne doit sortir
        config()->set('geoscan.request_delay', 0);
    }

    #[Test]
    public function il_envoie_toujours_un_user_agent_identifiable(): void
    {
        Http::fake(['*' => Http::response('<html></html>')]);
        config()->set('geoscan.user_agent', 'GeoScanBot/1.0 (+contact)');

        app(ShodanClient::class)->get('/search', ['query' => 'nginx']);

        Http::assertSent(
            fn ($request) => $request->hasHeader('User-Agent', 'GeoScanBot/1.0 (+contact)')
        );
    }

    #[Test]
    public function il_refuse_les_chemins_interdits_par_robots_txt(): void
    {
        Http::fake();
        config()->set('geoscan.disallowed_paths', ['/domain/']);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('interdit par robots.txt');

        try {
            app(ShodanClient::class)->get('/domain/dns.google');
        } finally {
            Http::assertNothingSent();    // la requete n'est meme pas partie
        }
    }

    #[Test]
    public function il_applique_le_delai_minimum_entre_deux_requetes(): void
    {
        Http::fake(['*' => Http::response('<html></html>')]);
        config()->set('geoscan.request_delay', 1);

        $client = app(ShodanClient::class);

        $start = microtime(true);
        $client->get('/search', ['query' => 'a']);   // 1re requete : pas d'attente
        $client->get('/search', ['query' => 'b']);   // 2e : doit attendre ~1 s
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        Http::assertSentCount(2);
    }

    #[Test]
    public function il_signale_clairement_le_mur_de_connexion_de_shodan(): void
    {
        Http::fake(['*' => Http::response(
            '<div class="alert alert-error"><p>Please log in to use search filters.</p></div>'
        )]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('filtres de recherche');

        app(ShodanClient::class)->get('/search', ['query' => 'country:"FR"']);
    }

    #[Test]
    public function il_transforme_un_timeout_reseau_en_message_comprehensible(): void
    {
        // Shodan does not answer at all: Guzzle throws a ConnectionException,
        // which must never reach the user raw.
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        config()->set('geoscan.timeout', 20);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage("n'a pas répondu dans le délai imparti (20 s)");

        app(ShodanClient::class)->get('/search', ['query' => 'apache']);
    }

    #[Test]
    public function il_leve_une_exception_sur_une_reponse_en_erreur(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('HTTP 503');

        app(ShodanClient::class)->get('/host/8.8.8.8');
    }

    #[Test]
    public function il_rejoue_une_panne_passagere_de_shodan(): void
    {
        // Observed 2026-09-01: shodan.io's edge intermittently returns
        // "upstream connect error" on /search. The very same request, replayed,
        // succeeds. Without a retry, a scan of dozens of sub-requests dies on
        // that hiccup.
        config()->set('geoscan.retries', 2);

        Http::fake(['*' => Http::sequence()
            ->push('upstream connect error', 503)
            ->push('<html>resultats</html>'),
        ]);

        $body = app(ShodanClient::class)->get('/search', ['query' => 'nginx']);

        $this->assertSame('<html>resultats</html>', $body);
        Http::assertSentCount(2);
    }

    #[Test]
    public function il_rejoue_aussi_un_timeout_reseau(): void
    {
        // This path had no test, and that is exactly how it nearly broke: the
        // retry decision used to be taken by matching the exception's French
        // wording, so simply adding accents to that sentence would have
        // silently disabled timeout retries. The decision is now carried by a
        // flag on the exception, and this test pins it down.
        config()->set('geoscan.retries', 2);

        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push('<html>resultats</html>'),
        ]);

        $body = app(ShodanClient::class)->get('/search', ['query' => 'nginx']);

        $this->assertSame('<html>resultats</html>', $body);
        Http::assertSentCount(2);
    }

    #[Test]
    public function il_renonce_apres_le_nombre_de_reprises_configure(): void
    {
        config()->set('geoscan.retries', 2);

        Http::fake(['*' => Http::response('toujours en panne', 503)]);

        try {
            app(ShodanClient::class)->get('/search', ['query' => 'nginx']);
            $this->fail('Une ScrapingException etait attendue.');
        } catch (ScrapingException) {
            // 1 attempt + 2 retries, not one more.
            Http::assertSentCount(3);
        }
    }

    #[Test]
    public function il_ne_rejoue_pas_une_reponse_qui_nous_vise(): void
    {
        // A 404 will not change its mind: insisting is pure noise.
        config()->set('geoscan.retries', 2);

        Http::fake(['*' => Http::response('introuvable', 404)]);

        try {
            app(ShodanClient::class)->get('/host/8.8.8.8');
            $this->fail('Une ScrapingException etait attendue.');
        } catch (ScrapingException) {
            Http::assertSentCount(1);
        }
    }
}
