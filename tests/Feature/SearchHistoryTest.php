<?php

namespace Tests\Feature;

use App\Models\Search;
use App\Services\Shodan\SearchScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

/**
 * Step 8: reading the history is an archive read, never a fresh scrape.
 * Http::preventStrayRequests() fails the test on the slightest unfaked outbound
 * call, and assertNothingSent verifies no request went out at all.
 */
class SearchHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
    }

    private function archiveUneRecherche(string $query = 'nginx'): Search
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);
        $search = app(SearchScraper::class)->scrape($query);
        Http::fake();          // on repart d'un compteur de requetes propre

        return $search;
    }

    #[Test]
    public function la_page_daccueil_mene_a_lhistorique_des_recherches(): void
    {
        // The search flow -- scrape, then read back the archive -- is what the
        // application is for. A visitor should land on it, not on a side
        // feature.
        $this->get('/')->assertRedirect('/recherches');

        Http::assertNothingSent();
    }

    #[Test]
    public function la_liste_de_lhistorique_ne_declenche_aucune_requete(): void
    {
        $this->archiveUneRecherche();

        $this->get(route('searches.index'))
            ->assertOk()
            ->assertSee('nginx');

        Http::assertNothingSent();
    }

    #[Test]
    public function consulter_une_archive_ne_declenche_aucune_requete(): void
    {
        $search = $this->archiveUneRecherche();

        $this->get(route('searches.show', $search))
            ->assertOk()
            ->assertSee('53 611 312')      // le total tel qu'archive
            ->assertSee('Top ports');

        Http::assertNothingSent();
    }

    #[Test]
    public function la_fiche_recherche_liste_les_cameras_candidates_sans_requete(): void
    {
        $search = $this->archiveUneRecherche();

        $this->get(route('searches.show', $search))
            ->assertOk()
            ->assertSee('Caméras candidates')
            ->assertSee('202.182.118.34')                       // une IP archivee
            ->assertSee('https://202.182.118.34:5263');         // le lien direct, schema d'origine

        Http::assertNothingSent();
    }

    #[Test]
    public function lhistorique_affiche_les_recherches_de_la_plus_recente_a_la_plus_ancienne(): void
    {
        $ancienne = Search::create(['query' => 'ancienne', 'total_results' => 1, 'scraped_at' => now()->subDay()]);
        $recente = Search::create(['query' => 'recente',  'total_results' => 2, 'scraped_at' => now()]);

        $this->get(route('searches.index'))
            ->assertOk()
            ->assertSeeInOrder([$recente->query, $ancienne->query]);
    }

    #[Test]
    public function le_formulaire_de_recherche_lui_declenche_bien_un_scraping(): void
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);

        $this->post(route('searches.store'), ['query' => 'apache'])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search'));
        $this->assertDatabaseHas('searches', ['query' => 'apache']);
    }

    #[Test]
    public function un_timeout_renvoie_au_formulaire_avec_un_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->post(route('searches.store'), ['query' => 'apache'])
            ->assertRedirect()
            ->assertSessionHasErrors('query');

        $this->assertDatabaseCount('searches', 0);
    }

    #[Test]
    public function une_requete_avec_filtre_affiche_un_message_explicite(): void
    {
        Http::fake(['*' => Http::response(
            '<div class="alert alert-error"><p>Please log in to use search filters.</p></div>'
        )]);

        $this->post(route('searches.store'), ['query' => 'country:"FR"'])
            ->assertRedirect()
            ->assertSessionHasErrors('query');

        $this->assertDatabaseCount('searches', 0);
    }
}
