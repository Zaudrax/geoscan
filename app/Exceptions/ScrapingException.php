<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A failure to fetch or to parse a remote page.
 *
 * Named constructors rather than raw messages: the wording of an error is shown
 * to the user, so it belongs next to the condition that produces it instead of
 * being written differently at each throw site.
 */
class ScrapingException extends RuntimeException
{
    /**
     * Whether the failure is worth retrying.
     *
     * Carried as a flag rather than inferred from the message. The retry logic
     * used to match on the wording, which silently broke the moment that
     * wording changed -- display text is for humans, and must never be load
     * bearing for control flow.
     */
    private bool $transient = false;

    public function isTransient(): bool
    {
        return $this->transient;
    }

    private function markTransient(): self
    {
        $this->transient = true;

        return $this;
    }

    public static function disallowedByRobots(string $path): self
    {
        return new self("Le chemin « {$path} » est interdit par robots.txt : requête annulée.");
    }

    /** A timeout or a dropped connection: the same request may well succeed. */
    public static function unreachable(string $url, int $timeout): self
    {
        return (new self(
            "Shodan n'a pas répondu dans le délai imparti ({$timeout} s) pour {$url}. "
            .'Le site est momentanément injoignable ou limite le débit : réessaie dans '
            .'quelques instants.'
        ))->markTransient();
    }

    /**
     * Only 5xx is worth retrying: a 404 or a 403 will not change its mind, and
     * insisting would just be noise.
     */
    public static function httpFailure(string $url, int $status): self
    {
        $exception = new self("Shodan a répondu HTTP {$status} pour {$url}.");

        return $status >= 500 ? $exception->markTransient() : $exception;
    }

    public static function loginRequired(): self
    {
        return new self(
            'Shodan demande une connexion pour cette requête. Les filtres de recherche '
            .'(country:, port:, org:…) sont réservés aux comptes connectés : essaie une '
            .'requête simple, par exemple « nginx » ou « apache ».'
        );
    }

    /** Credentials missing from .env: we do not even attempt to log in. */
    public static function loginNotConfigured(): self
    {
        return new self(
            'Aucun compte Shodan configuré. Renseigne SHODAN_LOGIN_ENABLED=true, '
            .'SHODAN_EMAIL et SHODAN_PASSWORD dans .env : les filtres de recherche '
            .'(country:, port:…) dont depend l\'enumeration exigent une session.'
        );
    }

    /** Le formulaire a ete soumis mais Shodan n'a pas ouvert de session. */
    public static function loginFailed(string $reason): self
    {
        return new self("Connexion à Shodan refusée : {$reason}");
    }

    /** Le cookie recopie a expire : seul l'utilisateur peut en fournir un neuf. */
    public static function sessionCookieExpired(): self
    {
        return new self(
            'La session Shodan recopiée dans SHODAN_SESSION_COOKIE a expiré. '
            .'Reconnecte-toi sur shodan.io dans ton navigateur, recopie l\'en-tete '
            .'Cookie d\'une requête vers shodan.io, et remplace la valeur dans .env. '
            .'Vérifie ensuite avec « php artisan geoscan:session ».'
        );
    }

    /** La valeur fournie ne ressemble pas a un en-tete Cookie. */
    public static function sessionCookieUnreadable(): self
    {
        return new self(
            'SHODAN_SESSION_COOKIE ne contient aucun cookie exploitable. Attendu : '
            .'l\'en-tête Cookie complet, de la forme « nom=valeur; autre=valeur ».'
        );
    }

    /** The login page does not have the structure we expect. */
    public static function loginFormUnreadable(): self
    {
        return new self(
            'Le formulaire de connexion de Shodan est illisible : sa structure a '
            .'probablement changé. Vérifie la page manuellement dans un navigateur.'
        );
    }

    public static function unparsable(string $what): self
    {
        return new self("Impossible d'extraire {$what} : la structure de la page a probablement change.");
    }
}
