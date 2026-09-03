<?php

namespace App\Services\Geo;

use App\Models\GeoPoint;
use App\Models\ScanResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gives harvested IPs an approximate position.
 *
 * Shodan exposes no coordinates, neither on a search page nor on a host page:
 * it gives a country and a city, spelled out. So we ask Photon (a geocoder
 * built on OpenStreetMap) where "Warsaw, Poland" is, once, and keep the answer
 * in the database forever.
 *
 * Two guards, out of courtesy towards a free service:
 *   - an identifiable User-Agent;
 *   - at most one request per second.
 *
 * And a third one, dictated by common sense: a cap on resolutions per call, so
 * that rendering a map never turns into a minute of waiting. Whatever was not
 * resolved this time will be on the next render.
 */
class Geocoder
{
    /** Horodatage du dernier appel sortant, pour tenir la cadence d'1 req/s. */
    private ?float $lastRequestAt = null;

    /**
     * Resolves whatever is missing, then returns the known points indexed by
     * their "CC|City" key.
     *
     * @param  Collection<int, array{country_code: ?string, city: ?string}>|Collection<int, ScanResult>  $places
     * @return array<string, array{latitude: float, longitude: float, label: string, source: string}>
     */
    public function pointsFor(Collection $places): array
    {
        $wanted = $this->distinctPlaces($places);

        if ($wanted === []) {
            return [];
        }

        $known = GeoPoint::query()
            ->whereIn('country_code', array_unique(array_column($wanted, 'country_code')))
            ->get()
            ->keyBy(fn (GeoPoint $point) => $point->key());

        $budget = (int) config('geoscan.geocoding.max_lookups', 12);

        foreach ($wanted as $place) {
            $key = GeoPoint::keyFor($place['country_code'], $place['city']);

            if (($known[$key] ?? null)?->isResolved()) {
                continue;
            }

            if ($budget <= 0 || ! config('geoscan.geocoding.enabled')) {
                break;
            }

            $budget--;
            $point = $this->resolve($place['country_code'], $place['city']);

            if ($point) {
                $known[$point->key()] = $point;
            }
        }

        return $this->index($known, $wanted);
    }

    /**
     * The (country, city) pairs to resolve, plus each country's centroid,
     * which serves as the fallback for unknown or unresolvable cities.
     *
     * @return list<array{country_code: string, city: ?string}>
     */
    private function distinctPlaces(Collection $places): array
    {
        $wanted = [];

        foreach ($places as $place) {
            $countryCode = strtoupper((string) ($place['country_code'] ?? ''));

            if ($countryCode === '') {
                continue;
            }

            $city = trim((string) ($place['city'] ?? ''));

            // The country centroid first: it is the safety net.
            $wanted[GeoPoint::keyFor($countryCode, null)] = [
                'country_code' => $countryCode,
                'city' => null,
            ];

            if ($city !== '') {
                $wanted[GeoPoint::keyFor($countryCode, $city)] = [
                    'country_code' => $countryCode,
                    'city' => $city,
                ];
            }
        }

        return array_values($wanted);
    }

    /**
     * Queries the geocoder for one place and remembers the outcome.
     *
     * A failure is remembered too (attempts incremented, coordinates left null)
     * so we do not re-ask the same losing question on every render.
     */
    private function resolve(string $countryCode, ?string $city): ?GeoPoint
    {
        $point = GeoPoint::firstOrNew([
            'country_code' => $countryCode,
            'city' => $city,
        ]);

        // Already attempted several times without success: this place is not
        // geocodable, and asking again would just be noise.
        if (! $point->isResolved() && $point->attempts >= 3) {
            return $point;
        }

        $countryName = (string) config("countries.{$countryCode}", $countryCode);

        // "layer" avoids the classic trap: without it, searching "Poland"
        // lands on Poland, Ohio.
        $parameters = $city !== null && $city !== ''
            ? ['q' => "{$city}, {$countryName}", 'layer' => 'city']
            : ['q' => $countryName, 'layer' => 'country'];

        $coordinates = $this->ask($parameters, $countryCode);

        $point->fill([
            'attempts' => $point->attempts + 1,
            'latitude' => $coordinates['latitude'] ?? null,
            'longitude' => $coordinates['longitude'] ?? null,
            'source' => $coordinates === [] ? null : GeoPoint::SOURCE_GEOCODER,
            'resolved_at' => $coordinates === [] ? null : now(),
        ])->save();

        return $point;
    }

    /**
     * One call to the geocoder, throttled to one request per second.
     *
     * @param  array<string, string>  $parameters
     * @return array{latitude?: float, longitude?: float}
     */
    private function ask(array $parameters, string $countryCode): array
    {
        $this->respectRateLimit();

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('geoscan.geocoding.user_agent'),
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('geoscan.geocoding.timeout', 15))
                ->get((string) config('geoscan.geocoding.endpoint'), [
                    ...$parameters,
                    ...(array) config('geoscan.geocoding.extra_params', []),
                    'limit' => '5',
                ]);
        } catch (ConnectionException) {
            $this->lastRequestAt = microtime(true);

            return [];
        }

        $this->lastRequestAt = microtime(true);

        if (! $response->successful()) {
            Log::info('geoscan.geocoding', ['status' => $response->status(), 'query' => $parameters]);

            return [];
        }

        return $this->firstMatch($response->json() ?? [], $countryCode);
    }

    /**
     * The first result located in the expected country.
     *
     * Two formats are accepted so the endpoint stays interchangeable: Photon
     * returns GeoJSON (coordinates as [longitude, latitude], country code in
     * properties.countrycode), Nominatim a flat list of
     * {lat, lon, address.country_code} objects.
     *
     * Checking the country is not cosmetic: a same-named city in another
     * country would drop the marker on the wrong continent.
     *
     * @param  array<mixed>  $payload
     * @return array{latitude?: float, longitude?: float}
     */
    private function firstMatch(array $payload, string $countryCode): array
    {
        $candidates = $payload['features'] ?? $payload;

        if (! is_array($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $found = $this->coordinatesOf($candidate);

            if ($found === []) {
                continue;
            }

            $foundCountry = strtoupper((string) (
                $candidate['properties']['countrycode']
                ?? $candidate['address']['country_code']
                ?? ''
            ));

            // A geocoder that does not state the country is taken at its word.
            if ($foundCountry === '' || $foundCountry === strtoupper($countryCode)) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $candidate
     * @return array{latitude?: float, longitude?: float}
     */
    private function coordinatesOf(array $candidate): array
    {
        // Photon: GeoJSON, [longitude, latitude] in that order.
        $geometry = $candidate['geometry']['coordinates'] ?? null;

        if (is_array($geometry) && isset($geometry[0], $geometry[1])) {
            return [
                'latitude' => (float) $geometry[1],
                'longitude' => (float) $geometry[0],
            ];
        }

        // Nominatim: lat and lon flat, as strings.
        if (isset($candidate['lat'], $candidate['lon'])) {
            return [
                'latitude' => (float) $candidate['lat'],
                'longitude' => (float) $candidate['lon'],
            ];
        }

        return [];
    }

    private function respectRateLimit(): void
    {
        $delay = (int) config('geoscan.geocoding.request_delay', 1);

        if ($delay <= 0 || $this->lastRequestAt === null) {
            return;
        }

        $waitFor = $delay - (microtime(true) - $this->lastRequestAt);

        if ($waitFor > 0) {
            usleep((int) round($waitFor * 1_000_000));
        }
    }

    /**
     * Assembles the final result: every requested place gets a position, its
     * own when known, its country's otherwise.
     *
     * @param  Collection<string, GeoPoint>  $known
     * @param  list<array{country_code: string, city: ?string}>  $wanted
     * @return array<string, array{latitude: float, longitude: float, label: string, source: string}>
     */
    private function index(Collection $known, array $wanted): array
    {
        $points = [];

        foreach ($wanted as $place) {
            $key = GeoPoint::keyFor($place['country_code'], $place['city']);
            $point = $known[$key] ?? null;
            $source = GeoPoint::SOURCE_GEOCODER;

            if (! $point?->isResolved()) {
                $point = $known[GeoPoint::keyFor($place['country_code'], null)] ?? null;
                $source = GeoPoint::SOURCE_COUNTRY;
            }

            if (! $point?->isResolved()) {
                continue;
            }

            $points[$key] = [
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'label' => trim(($place['city'] ?? '').' '.config("countries.{$place['country_code']}", $place['country_code'])),
                'source' => $source,
            ];
        }

        return $points;
    }
}
