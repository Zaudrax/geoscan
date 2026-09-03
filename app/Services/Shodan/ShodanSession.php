<?php

namespace App\Services\Shodan;

use App\Exceptions\ScrapingException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Opens and keeps an authenticated session on shodan.io.
 *
 * Why this is necessary: Shodan answers 200 to an anonymous visitor using a
 * filter (country:, port:, org:...), but replaces the results with
 * "Please log in to use search filters". Since the whole enumeration rests on
 * filters, it is impossible without an account.
 *
 * Two ways to obtain that session, in priority order:
 *
 *   1. a Cookie header copied from an already logged in browser. This is the
 *      only route for an account created through "Sign in with Google": it has
 *      no password, so there is no form to replay. Cost: zero requests;
 *   2. the login form, for an account that does have a password.
 *
 * The cookie jar is encrypted and cached: we do not replay the form on every
 * sub-request of a scan, which would be both slow and conspicuous to Shodan.
 *
 * Credentials and cookie alike live only in .env. They are never logged, and
 * never echoed back in an error message.
 */
class ShodanSession
{
    private const CACHE_KEY = 'geoscan:shodan_cookies';

    /** The current request's jar, so we never decrypt twice in one request. */
    private ?CookieJar $jar = null;

    public function isEnabled(): bool
    {
        if (! config('geoscan.login.enabled')) {
            return false;
        }

        return $this->hasSessionCookie() || $this->hasCredentials();
    }

    /** A session copied from a browser: nothing to replay. */
    private function hasSessionCookie(): bool
    {
        return filled(config('geoscan.login.session_cookie'));
    }

    /** An email + password pair usable against the login form. */
    private function hasCredentials(): bool
    {
        return filled(config('geoscan.login.email'))
            && filled(config('geoscan.login.password'));
    }

    /**
     * The cookie jar to attach to requests, or null when the application is
     * meant to stay anonymous.
     *
     * @throws ScrapingException
     */
    public function jar(): ?CookieJar
    {
        if (! $this->isEnabled()) {
            return null;
        }

        // A supplied cookie is authoritative: there is no cache to consult and
        // no form to replay, the session comes straight from .env.
        if ($this->hasSessionCookie()) {
            return $this->jar ??= $this->jarFromCookieHeader(
                (string) config('geoscan.login.session_cookie')
            );
        }

        return $this->jar ??= $this->restore() ?? $this->login();
    }

    /**
     * Forces a reconnection: called when Shodan serves the login wall while we
     * believed we were authenticated, i.e. when the session expired server
     * side.
     *
     * @throws ScrapingException
     */
    public function refresh(): ?CookieJar
    {
        if (! $this->isEnabled()) {
            return null;
        }

        // A copied cookie cannot renew itself: if Shodan rejects it, it has
        // expired and a fresh one has to be copied over.
        if ($this->hasSessionCookie()) {
            throw ScrapingException::sessionCookieExpired();
        }

        $this->forget();

        return $this->jar = $this->login();
    }

    /**
     * Turns a Cookie header into a jar Guzzle can use.
     *
     * The domain is not part of the header, so we derive it from the target URL
     * minus its subdomain, letting the same cookie serve www.shodan.io and
     * account.shodan.io alike.
     *
     * @throws ScrapingException
     */
    private function jarFromCookieHeader(string $header): CookieJar
    {
        $jar = new CookieJar;
        $domain = $this->cookieDomain();

        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($value === null || trim((string) $name) === '') {
                continue;
            }

            $jar->setCookie(new SetCookie([
                'Name' => trim((string) $name),
                'Value' => trim($value),
                'Domain' => $domain,
                'Path' => '/',
                'Secure' => true,
            ]));
        }

        if ($jar->count() === 0) {
            throw ScrapingException::sessionCookieUnreadable();
        }

        return $jar;
    }

    /** ".shodan.io" from "https://www.shodan.io". */
    private function cookieDomain(): string
    {
        $host = (string) parse_url((string) config('geoscan.base_url'), PHP_URL_HOST);
        $labels = explode('.', $host);

        // Keep the last two labels: the cookie must hold for every subdomain
        // of shodan.io.
        return '.'.implode('.', array_slice($labels, -2));
    }

    public function forget(): void
    {
        $this->jar = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Replays the login form and keeps the cookies it hands back.
     *
     * @throws ScrapingException
     */
    private function login(): CookieJar
    {
        $url = (string) config('geoscan.login.url');
        $jar = new CookieJar;

        $form = $this->readLoginForm($url, $jar);

        try {
            $response = Http::withOptions(['cookies' => $jar])
                ->withHeaders($this->headers())
                ->timeout((int) config('geoscan.timeout', 20))
                ->asForm()
                ->post($form['action'], $form['payload']);
        } catch (ConnectionException) {
            throw ScrapingException::loginFailed('le serveur de connexion ne répond pas.');
        }

        $this->assertSessionOpened($response->body(), $jar);

        $this->remember($jar);

        Log::info('geoscan.login', ['url' => $form['action'], 'status' => $response->status()]);

        return $jar;
    }

    /**
     * Fetches the login page and builds the POST payload.
     *
     * We echo back EVERY hidden field as-is (CSRF token included) rather than
     * targeting one by name: that is what lets this survive Shodan renaming the
     * token.
     *
     * @return array{action: string, payload: array<string, string>}
     *
     * @throws ScrapingException
     */
    private function readLoginForm(string $url, CookieJar $jar): array
    {
        try {
            $page = Http::withOptions(['cookies' => $jar])
                ->withHeaders($this->headers())
                ->timeout((int) config('geoscan.timeout', 20))
                ->get($url);
        } catch (ConnectionException) {
            throw ScrapingException::loginFailed('la page de connexion est injoignable.');
        }

        if (! $page->successful()) {
            throw ScrapingException::loginFailed("la page de connexion a répondu HTTP {$page->status()}.");
        }

        $crawler = new Crawler($page->body());
        $form = $crawler->filter('form')->reduce(
            fn (Crawler $node) => $node->filter('input[type="password"]')->count() > 0
        );

        if ($form->count() === 0) {
            throw ScrapingException::loginFormUnreadable();
        }

        $form = $form->first();
        $payload = [];

        $form->filter('input')->each(function (Crawler $input) use (&$payload): void {
            $name = $input->attr('name');

            if ($name === null || $name === '') {
                return;
            }

            $payload[$name] = match ($input->attr('type')) {
                'password' => (string) config('geoscan.login.password'),
                'email', 'text' => (string) config('geoscan.login.email'),
                default => (string) ($input->attr('value') ?? ''),
            };
        });

        return [
            'action' => $this->resolveAction($url, $form->attr('action')),
            'payload' => $payload,
        ];
    }

    /** A form action is often relative, and sometimes missing entirely. */
    private function resolveAction(string $loginUrl, ?string $action): string
    {
        if ($action === null || trim($action) === '') {
            return $loginUrl;
        }

        if (Str::startsWith($action, ['http://', 'https://'])) {
            return $action;
        }

        $parts = parse_url($loginUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return $origin.'/'.ltrim($action, '/');
    }

    /**
     * Shodan returns 200 even for bad credentials: it simply re-renders the
     * form. Two signals settle it.
     *
     * @throws ScrapingException
     */
    private function assertSessionOpened(string $body, CookieJar $jar): void
    {
        if ($this->cookieArray($jar) === []) {
            throw ScrapingException::loginFailed('aucun cookie de session n\'a été déposé.');
        }

        if (Str::contains($body, ['Invalid username or password', 'Please correct the errors'], ignoreCase: true)) {
            throw ScrapingException::loginFailed('identifiants refusés (vérifie SHODAN_EMAIL et SHODAN_PASSWORD dans .env).');
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'User-Agent' => (string) config('geoscan.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }

    /** Encrypts then caches the jar: a session cookie is a secret. */
    private function remember(CookieJar $jar): void
    {
        Cache::put(
            self::CACHE_KEY,
            Crypt::encryptString(json_encode($this->cookieArray($jar), JSON_THROW_ON_ERROR)),
            now()->addSeconds((int) config('geoscan.login.session_ttl', 3600)),
        );
    }

    /** The cached jar, or null when it is missing or unreadable. */
    private function restore(): ?CookieJar
    {
        $payload = Cache::get(self::CACHE_KEY);

        if (! is_string($payload)) {
            return null;
        }

        try {
            $cookies = json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // App key rotated, or cache corrupted: we will just log in again.
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        return is_array($cookies) && $cookies !== [] ? new CookieJar(false, $cookies) : null;
    }

    /** @return list<array<string, mixed>> */
    private function cookieArray(CookieJar $jar): array
    {
        return array_values($jar->toArray());
    }
}
