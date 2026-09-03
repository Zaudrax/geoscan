<?php

namespace Tests\Feature;

use App\Models\Search;
use App\Services\Shodan\SearchScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

class SearchScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
    }

    #[Test]
    public function il_archive_la_recherche_et_ses_classements(): void
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);

        $search = app(SearchScraper::class)->scrape('nginx');

        $this->assertDatabaseHas('searches', [
            'query' => 'nginx',
            'total_results' => 53_611_312,
        ]);
        $this->assertCount(25, $search->facets);       // 5 groupes x 5 entrees
        $this->assertCount(5, $search->facetsByType());
    }

    #[Test]
    public function il_archive_aussi_les_hotes_individuels_de_la_page(): void
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);

        $search = app(SearchScraper::class)->scrape('nginx');

        // The fixture page lists 10 result blocks, each with an IP.
        $this->assertCount(10, $search->results);

        $first = $search->results->first();
        $this->assertSame('202.182.118.34', $first->ip);
        $this->assertSame(0, $first->position);
        $this->assertNotNull($first->serviceUrl());
    }

    #[Test]
    public function chaque_scraping_cree_une_nouvelle_archive_sans_ecraser_la_precedente(): void
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);

        app(SearchScraper::class)->scrape('nginx');
        app(SearchScraper::class)->scrape('nginx');

        $this->assertSame(2, Search::where('query', 'nginx')->count());
    }

    #[Test]
    public function il_suit_la_pagination_jusqua_ramener_tout_le_pool(): void
    {
        $pages = [
            1 => $this->pageWith(4, ['1.1.1.1', '2.2.2.2']),
            2 => $this->pageWith(4, ['3.3.3.3', '4.4.4.4']),
        ];

        Http::fake(fn ($request) => Http::response($pages[$this->pageOf($request)] ?? $this->pageWith(4, [])));

        $search = app(SearchScraper::class)->scrape('country:"SE" webcam');

        // 4 hosts announced, 2 per page -> two pages followed, then we stop.
        $this->assertCount(4, $search->results);
        $this->assertSame(['1.1.1.1', '2.2.2.2', '3.3.3.3', '4.4.4.4'], $search->results->pluck('ip')->all());
        Http::assertSentCount(2);
    }

    #[Test]
    public function il_sarrete_quand_une_page_napporte_aucun_hote_nouveau(): void
    {
        // Inflated total but Shodan serves the same page again: the free tier
        // subscription wall, or a pagination that loops.
        $page = $this->pageWith(500, ['9.9.9.9']);

        Http::fake(fn ($request) => Http::response($page));

        $search = app(SearchScraper::class)->scrape('country:"SE" webcam');

        $this->assertCount(1, $search->results);
        Http::assertSentCount(2);       // page 1, puis page 2 qui ne fait que doublonner
    }

    #[Test]
    public function une_page_suivante_illisible_arrete_la_pagination_sans_tout_perdre(): void
    {
        // Page 1 readable; page 2 = the free tier subscription wall, without the
        // expected structure. The search must survive on page 1 alone.
        Http::fake(fn ($request) => Http::response(
            $this->pageOf($request) === 1
                ? $this->pageWith(500, ['5.5.5.5', '6.6.6.6'])
                : '<html><body><p>Upgrade your account to see more results.</p></body></html>'
        ));

        $search = app(SearchScraper::class)->scrape('country:"SE" webcam');

        $this->assertCount(2, $search->results);
        $this->assertSame(500, $search->total_results);
    }

    #[Test]
    public function il_interroge_bien_la_page_de_recherche_publique(): void
    {
        Http::fake(['*' => Http::response(Fixture::searchPage())]);

        app(SearchScraper::class)->scrape('apache');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search')
            && str_contains($request->url(), 'query=apache'));
    }

    /** The page number a request asked for, defaulting to page 1. */
    private function pageOf(Request $request): int
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (int) ($query['page'] ?? 1);
    }

    /**
     * A minimal /search page: an announced total and a host list, exactly what
     * the parser requires to extract results.
     *
     * @param  list<string>  $ips
     */
    private function pageWith(int $total, array $ips): string
    {
        $results = array_map(fn (string $ip): string => <<<HTML
            <div class="result">
                <div class="heading">
                    <a href="/host/{$ip}" class="title">{$ip}</a>
                    <a href="http://{$ip}:80">ouvrir</a>
                </div>
            </div>
            HTML, $ips);

        return '<html><body><div class="summary"><h4 class="total-results">'.$total.'</h4></div>'
            .implode('', $results).'</body></html>';
    }
}
