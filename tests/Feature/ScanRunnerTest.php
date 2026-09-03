<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\ScanStep;
use App\Services\Shodan\Parsers\FacetPageParser;
use App\Services\Shodan\Parsers\SearchPageParser;
use App\Services\Shodan\ScanRunner;
use App\Services\Shodan\ShodanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

/**
 * L'enumeration par decoupage de filtres.
 *
 * No real request goes out: Shodan's pages are built to order so we can drive
 * totals and rankings, which is the only way to exercise the algorithm's
 * decision tree.
 */
class ScanRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('geoscan.request_delay', 0);
        config()->set('geoscan.enumeration.expand_facets', false);
        config()->set('geoscan.enumeration.page_limit', 2);
        config()->set('geoscan.enumeration.per_page', 10);
    }

    #[Test]
    public function il_moissonne_directement_une_requete_qui_tient_sous_le_plafond(): void
    {
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(3, [
                ['ip' => '10.0.0.1', 'port' => 80],
                ['ip' => '10.0.0.2', 'port' => 443],
                ['ip' => '10.0.0.3', 'port' => 8080],
            ]),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(3, $scan->unique_hosts);
        $this->assertSame(1, $scan->requests_used);      // une page, rien a decouper
        $this->assertSame(Scan::STATUS_COMPLETED, $scan->status);
        $this->assertSame(
            [ScanStep::DECISION_HARVESTED],
            $scan->steps->pluck('decision')->unique()->values()->all(),
        );
    }

    #[Test]
    public function il_decoupe_par_port_et_ramene_la_totalite_des_resultats(): void
    {
        // The reference case: 39 results announced, 20 readable. The ranking's
        // five ports only cover 32 of them -- the other 7 sit on ports Shodan
        // names nowhere.
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(39, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 12],
                    ['label' => '8080', 'filter' => 'port:8080', 'count' => 9],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 6],
                    ['label' => '8443', 'filter' => 'port:8443', 'count' => 3],
                    ['label' => '2266', 'filter' => 'port:2266', 'count' => 2],
                ],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                12, $this->hosts(10, 80, offset: 0), nextPage: 2,
            ),
            'country:"PL" port:8080 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                9, $this->hosts(9, 8080, offset: 100),
            ),
            // 443 (6) and 8443 (3) fit together under 10: one request.
            'country:"PL" port:443,8443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                9, $this->hosts(9, 443, offset: 200),
            ),
            'country:"PL" port:2266 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                2, $this->hosts(2, 2266, offset: 400),
            ),
            // The blind spot: everything on no port of the ranking.
            'country:"PL" -port:80,8080,443,8443,2266 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                7, $this->hosts(7, 9001, offset: 500),
            ),
        ], page2: [
            // The port:80 slice holds 12 results: the last 2 are on page 2.
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                12, $this->hosts(2, 80, offset: 10),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        // 12 + 9 + 9 + 2 + 7 = 39: all of them, where Shodan showed only 20 and
        // where the ranking alone yielded 32.
        $this->assertSame(39, $scan->unique_hosts);
        $this->assertSame(39, $scan->total_reported);
        $this->assertTrue($scan->beatTheCeiling());
        $this->assertGreaterThan($scan->visibleCeiling(), $scan->unique_hosts);

        // The trace must show the split decision, then the harvests.
        $decisions = $scan->steps->pluck('decision')->all();
        $this->assertSame(ScanStep::DECISION_SPLIT, $decisions[0]);
        $this->assertContains(ScanStep::DECISION_HARVESTED, $decisions);
    }

    #[Test]
    public function il_groupe_les_tranches_minuscules_en_une_seule_requete(): void
    {
        // What a complete ranking really looks like: a long tail of one-result
        // slices. One by one, that is a request and ten seconds of crawl delay
        // each.
        $ports = range(9001, 9030);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(30, [], [
                'Top Ports' => array_map(
                    fn (int $port) => ['label' => (string) $port, 'filter' => 'port:'.$port, 'count' => 1],
                    $ports,
                ),
            ]),
            'country:"PL" port:9001,9002,9003,9004,9005,9006,9007,9008,9009,9010 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 9001, offset: 0),
            ),
            'country:"PL" port:9011,9012,9013,9014,9015,9016,9017,9018,9019,9020 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 9011, offset: 100),
            ),
            'country:"PL" port:9021,9022,9023,9024,9025,9026,9027,9028,9029,9030 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 9021, offset: 200),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        // 30 slices, but only 3 harvest requests (plus the root).
        $this->assertSame(30, $scan->unique_hosts);
        $this->assertSame(4, $scan->requests_used);
        $this->assertSame(Scan::STATUS_COMPLETED, $scan->status);

        $this->assertSame(
            [
                'port:9001,9002,9003,9004,9005,9006,9007,9008,9009,9010',
                'port:9011,9012,9013,9014,9015,9016,9017,9018,9019,9020',
                'port:9021,9022,9023,9024,9025,9026,9027,9028,9029,9030',
            ],
            $scan->steps->where('decision', ScanStep::DECISION_HARVESTED)->pluck('applied_filter')->values()->all(),
        );
    }

    #[Test]
    public function il_repasse_par_la_meme_facette_pour_depasser_les_cent_valeurs(): void
    {
        // Shodan caps every facet page at 100 values. A pool larger than 100
        // ports can cover is only reachable by excluding the ports already
        // seen, then splitting the residual by port again -- at which point
        // the NEXT values appear.
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(32, [], [
                'Top Ports' => [['label' => '80', 'filter' => 'port:80', 'count' => 10]],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 80, offset: 0),
            ),
            // Round 2: the residual, whose ranking shows an unseen port.
            'country:"PL" -port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(22, [], [
                'Top Ports' => [['label' => '443', 'filter' => 'port:443', 'count' => 10]],
            ]),
            'country:"PL" -port:80 port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 443, offset: 100),
            ),
            // Round 3: what is on neither 80 nor 443, this time under the
            // ceiling and therefore harvestable as is.
            'country:"PL" -port:80 -port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                12, $this->hosts(10, 9999, offset: 200), nextPage: 2,
            ),
        ], page2: [
            'country:"PL" -port:80 -port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                12, $this->hosts(2, 9999, offset: 210),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        // 10 + 10 + 12: the last two batches were only reachable by coming
        // back through the port facet, negation in hand.
        $this->assertSame(32, $scan->unique_hosts);
        $this->assertSame(Scan::STATUS_COMPLETED, $scan->status);
    }

    #[Test]
    public function il_s_arrete_quand_la_negation_devient_trop_longue(): void
    {
        config()->set('geoscan.enumeration.max_query_length', 60);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(90, [], [
                'Top Ports' => [
                    ['label' => '10001', 'filter' => 'port:10001', 'count' => 5],
                    ['label' => '10002', 'filter' => 'port:10002', 'count' => 5],
                    ['label' => '10003', 'filter' => 'port:10003', 'count' => 5],
                    ['label' => '10004', 'filter' => 'port:10004', 'count' => 5],
                ],
            ]),
            'country:"PL" port:10001,10002 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 10001, offset: 0),
            ),
            'country:"PL" port:10003,10004 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 10003, offset: 100),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        // The slices are harvested, but the blind spot stays out of reach: the
        // scan says so rather than walking into a 414. It is the WHOLE query
        // that is measured, accumulated negations included.
        $this->assertSame(20, $scan->unique_hosts);
        $this->assertSame(
            1,
            $scan->steps
                ->where('decision', ScanStep::DECISION_ABANDONED)
                ->filter(fn ($step) => str_contains((string) $step->note, 'requête de négation trop longue'))
                ->count(),
        );
    }

    #[Test]
    public function il_ne_sonde_pas_le_creux_quand_le_classement_couvre_tout(): void
    {
        // The negation costs a request: it is only justified when something is
        // genuinely missing.
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(25, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 15],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 10],
                ],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                15, $this->hosts(10, 80, offset: 0), nextPage: 2,
            ),
            'country:"PL" port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                10, $this->hosts(10, 443, offset: 100),
            ),
        ], page2: [
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                15, $this->hosts(5, 80, offset: 10),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(25, $scan->unique_hosts);
        Http::assertNotSent(fn (Request $request) => str_contains($this->queryOf($request), '-port:'));
    }

    #[Test]
    public function un_scan_par_terme_decoupe_une_recherche_de_banniere(): void
    {
        // The pool of 81 yawcam webcams in miniature: a free text root, past the
        // ceiling, split by port exactly like a timestamped scan.
        $this->fakePages([
            'country:"SE" Server: yawcam' => Fixture::searchResultsPage(25, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 10],
                    ['label' => '8080', 'filter' => 'port:8080', 'count' => 10],
                    ['label' => '81', 'filter' => 'port:81', 'count' => 5],
                ],
            ]),
            'country:"SE" port:80 Server: yawcam' => Fixture::searchResultsPage(10, $this->hosts(10, 80, offset: 0)),
            'country:"SE" port:8080 Server: yawcam' => Fixture::searchResultsPage(10, $this->hosts(10, 8080, offset: 100)),
            'country:"SE" port:81 Server: yawcam' => Fixture::searchResultsPage(5, $this->hosts(5, 81, offset: 200)),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan([
            'country_code' => 'SE',
            'base_term' => 'Server: yawcam',
            'observed_on' => null,
            'observed_hour' => null,
            'observed_minute' => null,
            'observed_second' => null,
            'base_query' => 'country:"SE" Server: yawcam',
        ]));

        $this->assertSame(25, $scan->unique_hosts);          // 10 + 10 + 5, sans doublon
        $this->assertSame(25, $scan->total_reported);
        $this->assertTrue($scan->beatTheCeiling());
        $this->assertSame(ScanStep::DECISION_SPLIT, $scan->steps->first()->decision);
    }

    #[Test]
    public function il_traite_les_grosses_tranches_en_premier(): void
    {
        // The "More..." page returns dozens of slices, most of which weigh a
        // single result. Starting with the smallest burns the budget without
        // ever reaching the ones carrying the mass.
        //
        // Batching disabled: what this test exercises is the ORDER of the
        // slices, and a batch would blur that.
        config()->set('geoscan.enumeration.batchable_facets', []);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(25, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 18],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 7],
                ],
            ]),
            'country:"PL" port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                7, $this->hosts(7, 443, offset: 0),
            ),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                18, $this->hosts(10, 80, offset: 100), nextPage: 2,
            ),
        ], page2: [
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                18, $this->hosts(8, 80, offset: 110),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $harvested = $scan->steps
            ->where('decision', ScanStep::DECISION_HARVESTED)
            ->pluck('applied_filter')
            ->values()
            ->all();

        $this->assertSame(['port:80', 'port:443'], $harvested);
    }

    #[Test]
    public function il_redecoupe_une_tranche_qui_deborde_encore(): void
    {
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(50, [], [
                'Top Ports' => [['label' => '80', 'filter' => 'port:80', 'count' => 50]],
            ]),
            // Still 50: port is not enough, a second facet is needed.
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(50, [], [
                'Top Organizations' => [['label' => 'Acme', 'filter' => 'org:"Acme"', 'count' => 4]],
            ]),
            'country:"PL" port:80 org:"Acme" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                4, $this->hosts(4, 80, offset: 0),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(4, $scan->unique_hosts);
        $this->assertSame(2, $scan->steps->where('decision', ScanStep::DECISION_SPLIT)->count());
        $this->assertSame(2, $scan->results()->first()->scan->steps->max('depth'));
    }

    #[Test]
    public function il_moissonne_le_visible_d_une_branche_qu_il_ne_peut_plus_decouper(): void
    {
        // 30 results, no facet to slice them with. We cannot have everything,
        // but walking away empty handed would be absurd: Shodan shows 20, and
        // 20 beats zero.
        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                30, $this->hosts(10, 80, offset: 0), nextPage: 2,
            ),
        ], page2: [
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                30, $this->hosts(10, 80, offset: 10),
            ),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(20, $scan->unique_hosts);
        $this->assertSame(ScanStep::DECISION_ABANDONED, $scan->steps->first()->decision);
        $this->assertSame(20, $scan->steps->first()->harvested);
    }

    #[Test]
    public function il_ne_compte_qu_une_fois_une_ip_qui_ressort_dans_deux_tranches(): void
    {
        // A machine with two services answers both slices. The (IP, port) pair
        // stays unique, the IP does not: that is intended.
        //
        // Batching disabled: it would melt both slices into one request, and the
        // overlap BETWEEN slices is exactly what this test exercises.
        config()->set('geoscan.enumeration.batchable_facets', []);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(25, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 2],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 2],
                ],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(2, [
                ['ip' => '10.0.0.1', 'port' => 80],
                ['ip' => '10.0.0.2', 'port' => 80],
            ]),
            'country:"PL" port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(2, [
                ['ip' => '10.0.0.1', 'port' => 80],    // exactement le meme couple
                ['ip' => '10.0.0.1', 'port' => 443],   // meme IP, autre service
            ]),
        ]);

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(3, $scan->unique_hosts);
        $this->assertSame(2, $scan->results()->distinct()->count('ip'));
        $this->assertDatabaseCount('hosts', 2);
    }

    #[Test]
    public function il_publie_le_compteur_de_requetes_pendant_le_scan(): void
    {
        // A scan runs for minutes: without a counter written as it goes, the
        // view would show "0 requests" throughout, indistinguishable from a
        // stalled worker.
        config()->set('geoscan.enumeration.batchable_facets', []);

        $pages = [
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(25, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 10],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 10],
                    ['label' => '22', 'filter' => 'port:22', 'count' => 5],
                ],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(10, $this->hosts(10, 80)),
            'country:"PL" port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(10, $this->hosts(10, 443, offset: 100)),
            'country:"PL" port:22 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(5, $this->hosts(5, 22, offset: 200)),
        ];

        $scan = $this->scan();
        $observed = [];

        Http::fake(function (Request $request) use ($pages, $scan, &$observed) {
            // What the view would read from the database at this exact moment.
            $observed[] = (int) Scan::find($scan->id)->requests_used;

            return Http::response($pages[$this->queryOf($request)]);
        });

        $finished = (new ScanRunner(...$this->dependencies()))->run($scan);

        $this->assertSame([1, 2, 3, 4], $observed);
        $this->assertSame(4, $finished->requests_used);
    }

    #[Test]
    public function il_s_arrete_net_quand_le_budget_de_requetes_est_epuise(): void
    {
        // Batching disabled: we want three distinct slices to visit, so the
        // budget can run out midway.
        config()->set('geoscan.enumeration.batchable_facets', []);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(80, [], [
                'Top Ports' => [
                    ['label' => '80', 'filter' => 'port:80', 'count' => 2],
                    ['label' => '443', 'filter' => 'port:443', 'count' => 2],
                    ['label' => '22', 'filter' => 'port:22', 'count' => 2],
                ],
            ]),
            'country:"PL" port:80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(2, $this->hosts(2, 80)),
            'country:"PL" port:443 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(2, $this->hosts(2, 443, offset: 100)),
            'country:"PL" port:22 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(2, $this->hosts(2, 22, offset: 200)),
        ]);

        // Two requests only: the root, then a single slice.
        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan(['max_requests' => 2]));

        $this->assertSame(2, $scan->requests_used);
        Http::assertSentCount(2);
        $this->assertSame(Scan::STATUS_BUDGET_EXHAUSTED, $scan->status);
        $this->assertSame(2, $scan->unique_hosts);
    }

    #[Test]
    public function il_suit_le_lien_more_quand_le_classement_ne_couvre_pas_le_total(): void
    {
        // 24 results but a ranking that explains only 4: the distribution tail
        // is invisible on the search page. /search/facet reveals part of it,
        // and the negation goes after the rest.
        config()->set('geoscan.enumeration.expand_facets', true);

        $this->fakePages([
            'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(24, [], [
                'Top Ports' => [['label' => '80', 'filter' => 'port:80', 'count' => 4]],
            ]),
            'country:"PL" port:9999,80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                9, [...$this->hosts(5, 9999, offset: 100), ...$this->hosts(4, 80)],
            ),
            'country:"PL" -port:9999,80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                15, $this->hosts(10, 7777, offset: 200), nextPage: 2,
            ),
        ], page2: [
            'country:"PL" -port:9999,80 Date: Tue, 01 Sep 2026 09:13:03 GMT' => Fixture::searchResultsPage(
                15, $this->hosts(5, 7777, offset: 210),
            ),
        ], facetPage: Fixture::searchResultsPage(24, [], [
            'Top Ports' => [
                ['label' => '80', 'filter' => 'port:80', 'count' => 4],
                ['label' => '9999', 'filter' => 'port:9999', 'count' => 5],   // invisible sans le lien More
            ],
        ]));

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(24, $scan->unique_hosts);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/search/facet'));
        Http::assertSent(fn (Request $request) => str_contains($this->queryOf($request), '-port:9999,80'));
    }

    #[Test]
    public function une_sous_requete_en_erreur_n_interrompt_pas_le_scan(): void
    {
        // Batching disabled: two distinct slices are needed for exactly one of
        // them to fail.
        config()->set('geoscan.enumeration.batchable_facets', []);

        Http::fake(function (Request $request) {
            $query = $this->queryOf($request);

            if (str_contains($query, 'port:443')) {
                return Http::response('indisponible', 503);
            }

            return Http::response(match (true) {
                str_contains($query, 'port:80') => Fixture::searchResultsPage(2, $this->hosts(2, 80)),
                default => Fixture::searchResultsPage(40, [], [
                    'Top Ports' => [
                        ['label' => '443', 'filter' => 'port:443', 'count' => 3],
                        ['label' => '80', 'filter' => 'port:80', 'count' => 5],
                    ],
                ]),
            });
        });

        $scan = (new ScanRunner(...$this->dependencies()))->run($this->scan());

        $this->assertSame(2, $scan->unique_hosts);
        $this->assertSame(Scan::STATUS_COMPLETED, $scan->status);
        $this->assertSame(1, $scan->steps->where('decision', ScanStep::DECISION_FAILED)->count());
    }

    /**
     * A scan targets 2026-09-01 at 09:13:03 GMT in Poland, exactly as it would
     * be typed into Shodan's search bar.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function scan(array $attributes = []): Scan
    {
        return Scan::create([
            'country_code' => 'PL',
            'observed_on' => '2026-09-01',
            'observed_hour' => 9,
            'observed_minute' => 13,
            'observed_second' => 3,
            'base_query' => 'country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT',
            'max_requests' => 30,
            'status' => Scan::STATUS_RUNNING,
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    /** @return array{0: ShodanClient, 1: SearchPageParser, 2: FacetPageParser} */
    private function dependencies(): array
    {
        return [
            app(ShodanClient::class),
            app(SearchPageParser::class),
            app(FacetPageParser::class),
        ];
    }

    /**
     * Answers each call with the page matching its Shodan query.
     *
     * @param  array<string, string>  $pages  Shodan query => page 1 HTML
     * @param  array<string, string>  $page2  Shodan query => page 2 HTML
     */
    private function fakePages(array $pages, array $page2 = [], ?string $facetPage = null): void
    {
        Http::fake(function (Request $request) use ($pages, $page2, $facetPage) {
            if (str_contains($request->url(), '/search/facet')) {
                return Http::response($facetPage ?? '<html></html>');
            }

            $query = $this->queryOf($request);
            $wantedPage = (int) ($this->paramsOf($request)['page'] ?? 1);

            $html = $wantedPage > 1
                ? ($page2[$query] ?? null)
                : ($pages[$query] ?? null);

            if ($html === null) {
                $this->fail("Requete Shodan inattendue (page {$wantedPage}) : {$query}");
            }

            return Http::response($html);
        });
    }

    private function queryOf(Request $request): string
    {
        return (string) ($this->paramsOf($request)['query'] ?? '');
    }

    /** @return array<string, string> */
    private function paramsOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return $params;
    }

    /**
     * @return list<array{ip: string, port: int}>
     */
    private function hosts(int $count, int $port, int $offset = 0): array
    {
        return array_map(
            fn (int $index) => ['ip' => '10.0.'.intdiv($offset + $index, 256).'.'.(($offset + $index) % 256), 'port' => $port],
            range(1, $count),
        );
    }
}
