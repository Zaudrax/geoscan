<?php

namespace Tests\Feature;

use App\Exceptions\ScrapingException;
use App\Services\Shodan\ShodanClient;
use App\Services\Shodan\ShodanSession;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The authenticated session, without which Shodan accepts no search filter at
 * all -- and therefore without which the enumeration does not exist.
 */
class ShodanSessionTest extends TestCase
{
    private const LOGIN_URL = 'https://account.shodan.io/login';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
        config()->set('geoscan.login.url', self::LOGIN_URL);
    }

    #[Test]
    public function sans_identifiants_l_application_reste_anonyme(): void
    {
        config()->set('geoscan.login.enabled', false);
        Http::fake();

        $this->assertFalse(app(ShodanSession::class)->isEnabled());
        $this->assertNull(app(ShodanSession::class)->jar());
        Http::assertNothingSent();
    }

    #[Test]
    public function activer_la_connexion_sans_mot_de_passe_ne_suffit_pas(): void
    {
        config()->set('geoscan.login.enabled', true);
        config()->set('geoscan.login.email', 'moi@example.test');
        config()->set('geoscan.login.password', null);
        Http::fake();

        $this->assertFalse(app(ShodanSession::class)->isEnabled());
        Http::assertNothingSent();
    }

    #[Test]
    public function il_renvoie_le_formulaire_complet_champs_caches_compris(): void
    {
        // The CSRF token is not targeted by name: we echo back every hidden
        // field as-is, to survive Shodan renaming it.
        $this->enableLogin();
        $this->fakeLogin();

        app(ShodanSession::class)->jar();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            return $request['csrf_token'] === 'jeton-123'
                && $request['username'] === 'moi@example.test'
                && $request['password'] === 'secret'
                && $request['continue'] === 'https://www.shodan.io';
        });
    }

    #[Test]
    public function il_resout_une_action_de_formulaire_relative(): void
    {
        $this->enableLogin();
        $this->fakeLogin();

        app(ShodanSession::class)->jar();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://account.shodan.io/login');
    }

    #[Test]
    public function il_ne_rejoue_pas_le_formulaire_a_chaque_appel(): void
    {
        // A scan chains dozens of requests: reconnecting before each one would
        // be slow, and conspicuous to Shodan.
        $this->enableLogin();
        $this->fakeLogin();

        $session = app(ShodanSession::class);
        $session->jar();
        $session->jar();

        // A single GET + POST pair despite the two calls.
        Http::assertSentCount(2);
    }

    #[Test]
    public function il_refuse_de_continuer_quand_les_identifiants_sont_rejetes(): void
    {
        $this->enableLogin();

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginForm())
                ->push('<p>Invalid username or password</p>', 200, $this->sessionCookie()),
        ]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('identifiants refusés');

        app(ShodanSession::class)->jar();
    }

    #[Test]
    public function il_signale_une_page_de_connexion_illisible(): void
    {
        $this->enableLogin();
        Http::fake([self::LOGIN_URL => Http::response('<html><body>rien a voir</body></html>')]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('formulaire de connexion');

        app(ShodanSession::class)->jar();
    }

    #[Test]
    public function le_client_se_reconnecte_et_rejoue_la_requete_quand_la_session_a_expire(): void
    {
        $this->enableLogin();

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginForm())
                ->push('<p>Bienvenue</p>', 200, $this->sessionCookie())
                ->push($this->loginForm())
                ->push('<p>Bienvenue</p>', 200, $this->sessionCookie()),
            'www.shodan.io/*' => Http::sequence()
                ->push('<div>Please log in to use search filters.</div>')
                ->push('<html>resultats</html>'),
        ]);

        $body = app(ShodanClient::class)->get('/search', ['query' => 'country:"PL"']);

        $this->assertSame('<html>resultats</html>', $body);
    }

    #[Test]
    public function sans_compte_le_mur_de_connexion_reste_une_erreur(): void
    {
        config()->set('geoscan.login.enabled', false);
        Http::fake(['*' => Http::response('<div>Please log in to use search filters.</div>')]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('filtres de recherche');

        app(ShodanClient::class)->get('/search', ['query' => 'country:"PL"']);

        // One attempt only: with no credentials, insisting makes no sense.
        Http::assertSentCount(1);
    }

    #[Test]
    public function un_cookie_recopie_du_navigateur_evite_toute_connexion(): void
    {
        // The case of an account created through "Sign in with Google": it has
        // no password, so there is no form to replay at all.
        config()->set('geoscan.login.enabled', true);
        config()->set('geoscan.login.session_cookie', 'polito=xyz; session=abc123');

        Http::fake(['www.shodan.io/*' => Http::response('<html>ok</html>')]);

        $this->assertTrue(app(ShodanSession::class)->isEnabled());

        app(ShodanClient::class)->get('/search', ['query' => 'country:"PL"']);

        // A single request: the one we meant to make. No authentication at all.
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $cookies = $request->header('Cookie')[0] ?? '';

            return str_contains($cookies, 'session=abc123')
                && str_contains($cookies, 'polito=xyz');
        });
    }

    #[Test]
    public function le_cookie_a_la_priorite_sur_le_couple_email_mot_de_passe(): void
    {
        $this->enableLogin();
        config()->set('geoscan.login.session_cookie', 'session=abc123');

        Http::fake(['www.shodan.io/*' => Http::response('<html>ok</html>')]);

        app(ShodanClient::class)->get('/search', ['query' => 'country:"PL"']);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'account.shodan.io'));
    }

    #[Test]
    public function un_cookie_illisible_est_signale_tout_de_suite(): void
    {
        config()->set('geoscan.login.enabled', true);
        config()->set('geoscan.login.session_cookie', 'valeur collee de travers');

        Http::fake();

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('aucun cookie exploitable');

        app(ShodanSession::class)->jar();
    }

    #[Test]
    public function un_cookie_expire_demande_a_l_utilisateur_d_en_recopier_un_neuf(): void
    {
        // Unlike the form, a copied cookie cannot be renewed by the
        // application: it has to say so plainly.
        config()->set('geoscan.login.enabled', true);
        config()->set('geoscan.login.session_cookie', 'session=perime');

        Http::fake(['www.shodan.io/*' => Http::response('<div>Please log in to use search filters.</div>')]);

        $this->expectException(ScrapingException::class);
        $this->expectExceptionMessage('SHODAN_SESSION_COOKIE');

        app(ShodanClient::class)->get('/search', ['query' => 'country:"PL"']);
    }

    private function enableLogin(): void
    {
        config()->set('geoscan.login.enabled', true);
        config()->set('geoscan.login.email', 'moi@example.test');
        config()->set('geoscan.login.password', 'secret');
    }

    private function fakeLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginForm())
                ->push('<p>Bienvenue</p>', 200, $this->sessionCookie()),
        ]);
    }

    private function loginForm(): string
    {
        return <<<'HTML'
            <html><body>
            <form action="/login" method="post">
                <input type="hidden" name="csrf_token" value="jeton-123">
                <input type="text" name="username" value="">
                <input type="password" name="password" value="">
                <input type="hidden" name="continue" value="https://www.shodan.io">
                <button type="submit">Login</button>
            </form>
            </body></html>
            HTML;
    }

    /** @return array<string, string> */
    private function sessionCookie(): array
    {
        return ['Set-Cookie' => 'session=abc123; Domain=.shodan.io; Path=/'];
    }
}
