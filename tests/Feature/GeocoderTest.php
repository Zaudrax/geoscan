<?php

namespace Tests\Feature;

use App\Models\GeoPoint;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Services\Geo\Geocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le geocodage des IP moissonnees.
 *
 * Shodan publishes no coordinates: the geocoder places the points, and the
 * geo_points table guarantees we only bother it once per place.
 */
class GeocoderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('geoscan.geocoding.enabled', true);
        config()->set('geoscan.geocoding.request_delay', 0);
        config()->set('geoscan.geocoding.endpoint', 'https://photon.test/api/');
    }

    #[Test]
    public function il_resout_une_ville_et_la_garde_en_base(): void
    {
        Http::fake(['photon.test/*' => Http::response($this->photonResponse(52.2319, 21.0067))]);

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        $this->assertEqualsWithDelta(52.2319, $points['PL|Warsaw']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(21.0067, $points['PL|Warsaw']['longitude'], 0.0001);
        $this->assertSame(GeoPoint::SOURCE_GEOCODER, $points['PL|Warsaw']['source']);

        $this->assertDatabaseHas('geo_points', [
            'country_code' => 'PL',
            'city' => 'Warsaw',
            'source' => GeoPoint::SOURCE_GEOCODER,
        ]);
    }

    #[Test]
    public function il_cible_la_bonne_couche_pour_une_ville_et_pour_un_pays(): void
    {
        // Without "layer", searching "Poland" lands on Poland, Ohio.
        Http::fake(['photon.test/*' => Http::response($this->photonResponse(52.2, 21.0))]);

        app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        $layers = [];

        Http::assertSent(function (Request $request) use (&$layers) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            $layers[$params['layer']] = $params['q'];

            return true;
        });

        $this->assertSame('Warsaw, Pologne', $layers['city']);
        $this->assertSame('Pologne', $layers['country']);
    }

    #[Test]
    public function il_s_annonce_avec_un_user_agent_identifiable(): void
    {
        Http::fake(['photon.test/*' => Http::response($this->photonResponse(52.2, 21.0))]);

        app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        Http::assertSent(fn (Request $request) => str_contains(
            $request->header('User-Agent')[0] ?? '', 'GeoScanBot'
        ));
    }

    #[Test]
    public function il_ecarte_une_ville_homonyme_situee_dans_un_autre_pays(): void
    {
        // "Warsaw" also exists in Indiana: accepting it would drop the marker on
        // the wrong continent.
        Http::fake(['photon.test/*' => Http::response([
            'features' => [
                $this->photonFeature(41.2395, -85.8530, 'US'),
                $this->photonFeature(52.2319, 21.0067, 'PL'),
            ],
        ])]);

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        $this->assertEqualsWithDelta(52.2319, $points['PL|Warsaw']['latitude'], 0.0001);
    }

    #[Test]
    public function il_comprend_aussi_le_format_de_nominatim(): void
    {
        // L'endpoint reste interchangeable : une instance Nominatim
        // auto-hebergee doit fonctionner sans toucher au code.
        Http::fake(['photon.test/*' => Http::response([
            ['lat' => '52.2319', 'lon' => '21.0067', 'address' => ['country_code' => 'pl']],
        ])]);

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        $this->assertEqualsWithDelta(52.2319, $points['PL|Warsaw']['latitude'], 0.0001);
    }

    #[Test]
    public function il_ne_redemande_jamais_un_lieu_deja_resolu(): void
    {
        GeoPoint::create([
            'country_code' => 'PL', 'city' => 'Warsaw',
            'latitude' => 52.23, 'longitude' => 21.01,
            'source' => GeoPoint::SOURCE_GEOCODER, 'attempts' => 1, 'resolved_at' => now(),
        ]);
        GeoPoint::create([
            'country_code' => 'PL', 'city' => null,
            'latitude' => 51.92, 'longitude' => 19.14,
            'source' => GeoPoint::SOURCE_GEOCODER, 'attempts' => 1, 'resolved_at' => now(),
        ]);

        Http::fake();

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(52.23, $points['PL|Warsaw']['latitude'], 0.001);
    }

    #[Test]
    public function une_ville_introuvable_retombe_sur_le_centre_du_pays(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

            // The geocoder does not know this city, but it knows the country.
            return ($params['layer'] ?? null) === 'country'
                ? Http::response($this->photonResponse(51.9194, 19.1451))
                : Http::response(['features' => []]);
        });

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Ville Inexistante']]));

        $this->assertSame(GeoPoint::SOURCE_COUNTRY, $points['PL|Ville Inexistante']['source']);
        $this->assertEqualsWithDelta(51.9194, $points['PL|Ville Inexistante']['latitude'], 0.0001);
    }

    #[Test]
    public function un_echec_est_memorise_pour_ne_pas_etre_rejoue_indefiniment(): void
    {
        Http::fake(['photon.test/*' => Http::response(['features' => []])]);

        $results = $this->results([['city' => 'Nulle Part']]);

        app(Geocoder::class)->pointsFor($results);
        app(Geocoder::class)->pointsFor($results);
        app(Geocoder::class)->pointsFor($results);
        app(Geocoder::class)->pointsFor($results);   // le 4e appel ne doit plus rien tenter

        // 2 lieux (pays + ville) x 3 tentatives, pas davantage.
        Http::assertSentCount(6);
        $this->assertSame(3, GeoPoint::where('city', 'Nulle Part')->sole()->attempts);
    }

    #[Test]
    public function le_geocodage_desactive_ne_fait_sortir_aucune_requete(): void
    {
        config()->set('geoscan.geocoding.enabled', false);
        Http::fake();

        $points = app(Geocoder::class)->pointsFor($this->results([['city' => 'Warsaw']]));

        Http::assertNothingSent();
        $this->assertSame([], $points);
    }

    #[Test]
    public function il_plafonne_le_nombre_de_resolutions_par_affichage(): void
    {
        // At one request per second, a brand new map must not leave the user
        // waiting: the rest resolves on the next render.
        config()->set('geoscan.geocoding.max_lookups', 2);
        Http::fake(['photon.test/*' => Http::response($this->photonResponse(52.2, 21.0))]);

        app(Geocoder::class)->pointsFor($this->results([
            ['city' => 'Warsaw'], ['city' => 'Krakow'], ['city' => 'Gdansk'],
        ]));

        Http::assertSentCount(2);
    }

    /**
     * Une reponse Photon minimale : du GeoJSON, coordonnees en [lon, lat].
     *
     * @return array<string, mixed>
     */
    private function photonResponse(float $latitude, float $longitude, string $countryCode = 'PL'): array
    {
        return ['features' => [$this->photonFeature($latitude, $longitude, $countryCode)]];
    }

    /** @return array<string, mixed> */
    private function photonFeature(float $latitude, float $longitude, string $countryCode): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['countrycode' => $countryCode],
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
        ];
    }

    /**
     * @param  list<array{city: string}>  $cities
     * @return Collection<int, ScanResult>
     */
    private function results(array $cities): Collection
    {
        $scan = Scan::factory()->create(['country_code' => 'PL']);

        return collect($cities)->map(fn (array $city, int $index) => ScanResult::factory()->create([
            'scan_id' => $scan->id,
            'ip' => '10.0.0.'.($index + 1),
            'country_code' => 'PL',
            'city' => $city['city'],
        ]));
    }
}
