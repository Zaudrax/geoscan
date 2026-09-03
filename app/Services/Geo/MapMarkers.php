<?php

namespace App\Services\Geo;

use App\Models\GeoPoint;
use App\Models\ScanResult;
use Illuminate\Support\Collection;

/**
 * Turns scan results into points a map can draw.
 *
 * Extracted from the controller because it is neither routing nor persistence:
 * it is a rendering decision, and it carries the only piece of real geometry in
 * the application.
 */
class MapMarkers
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @param  Collection<int, ScanResult>  $results
     * @return list<array<string, mixed>>
     */
    public function for(Collection $results): array
    {
        $points = $this->geocoder->pointsFor($results);

        return $results
            ->map(fn (ScanResult $result) => $this->marker($result, $points[$result->geoKey()] ?? null))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{latitude: float, longitude: float, source: string}|null  $point
     * @return array<string, mixed>|null
     */
    private function marker(ScanResult $result, ?array $point): ?array
    {
        if ($point === null) {
            return null;
        }

        [$latitudeJitter, $longitudeJitter] = $this->jitterFor($result->ip);

        return [
            'ip' => $result->ip,
            'port' => $result->port,
            'city' => $result->city,
            'country' => $result->country,
            'organization' => $result->organization,
            'hostname' => $result->hostnames[0] ?? null,
            'observed_at' => $result->observed_at?->format('d/m/Y H:i:s'),
            'url' => route('hosts.show', $result->ip),
            // Told to the user rather than hidden: a country level point is a
            // guess, and a map that does not say so invites false conclusions.
            'approximate' => $point['source'] === GeoPoint::SOURCE_COUNTRY,
            'latitude' => round($point['latitude'] + $latitudeJitter, 6),
            'longitude' => round($point['longitude'] + $longitudeJitter, 6),
        ];
    }

    /**
     * A roughly kilometre-scale offset, stable for a given IP.
     *
     * Several machines share a city and therefore a coordinate; without this
     * they would stack into a single unclickable dot. Derived from the IP so a
     * marker never moves between two page loads -- a jitter that wanders would
     * suggest the machine itself moved.
     *
     * @return array{0: float, 1: float}
     */
    private function jitterFor(string $ip): array
    {
        $hash = crc32($ip);

        return [
            (($hash % 1000) / 1000 - 0.5) * 0.06,
            ((intdiv($hash, 1000) % 1000) / 1000 - 0.5) * 0.06,
        ];
    }
}
