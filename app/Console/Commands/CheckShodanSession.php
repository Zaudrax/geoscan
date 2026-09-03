<?php

namespace App\Console\Commands;

use App\Exceptions\ScrapingException;
use App\Services\Shodan\Parsers\SearchPageParser;
use App\Services\Shodan\ShodanClient;
use App\Services\Shodan\ShodanQuery;
use App\Services\Shodan\ShodanSession;
use Illuminate\Console\Command;

/**
 * Checks, in a single request, that Shodan still accepts our filters.
 *
 * Without it, the only way to know whether the session still holds is to start
 * a scan and wait several minutes to watch it fail on its very first step.
 */
class CheckShodanSession extends Command
{
    protected $signature = 'geoscan:session';

    protected $description = 'Vérifie que la session Shodan configurée permet bien les filtres de recherche';

    public function handle(ShodanSession $session, ShodanClient $client, SearchPageParser $parser): int
    {
        if (! $session->isEnabled()) {
            $this->components->error('Aucune session configurée : l\'application interrogera Shodan en anonyme.');
            $this->newLine();
            $this->line('  Renseigne dans <options=bold>.env</>, au choix :');
            $this->line('    <fg=cyan>SHODAN_LOGIN_ENABLED</>=true et <fg=cyan>SHODAN_SESSION_COOKIE</>="nom=valeur; …"');
            $this->line('      -> pour un compte créé avec « Se connecter avec Google » (pas de mot de passe)');
            $this->line('    <fg=cyan>SHODAN_LOGIN_ENABLED</>=true, <fg=cyan>SHODAN_EMAIL</> et <fg=cyan>SHODAN_PASSWORD</>');
            $this->line('      -> pour un compte qui a un mot de passe');

            return self::FAILURE;
        }

        // A country filtered query: precisely what an anonymous visitor is
        // refused, which makes it the discriminating test.
        $query = ShodanQuery::forCountry('FR');

        $this->components->info("Requête de contrôle : {$query}");

        try {
            $page = $parser->parse($client->get('/search', ['query' => $query->toString()]));
        } catch (ScrapingException $e) {
            $this->components->error('Session refusée.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Session valide : le filtre est appliqué, Shodan annonce %s résultats.',
            number_format($page['total_results'], 0, ',', ' '),
        ));

        $this->line(sprintf(
            '  Plafond consultable : %d résultats. Au-delà, c\'est l\'énumération qui prend le relais.',
            (int) config('geoscan.enumeration.page_limit') * (int) config('geoscan.enumeration.per_page'),
        ));

        return self::SUCCESS;
    }
}
