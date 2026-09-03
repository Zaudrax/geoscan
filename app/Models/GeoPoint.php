<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A coordinate resolved once and for all.
 *
 * Shodan n'expose ni latitude ni longitude. On demande au geocodeur ou se
 * found for "Warsaw, Poland", and we never ask again: the table IS the cache,
 * rather than an application cache that expires. Coordinates of a city do not
 * change, so an expiry would only buy us extra outbound requests.
 *
 * city null = country centroid, used as a fallback when the city is unknown
 * ou introuvable.
 */
class GeoPoint extends Model
{
    use HasFactory;

    public const SOURCE_GEOCODER = 'geocoder';

    public const SOURCE_COUNTRY = 'country_centroid';

    protected $fillable = [
        'country_code', 'city', 'latitude', 'longitude', 'source',
        'attempts', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Cle de regroupement stable, cote PHP comme cote JavaScript.
     * "PL|Warsaw" for a city, "PL|" for a country centroid.
     */
    public static function keyFor(?string $countryCode, ?string $city): string
    {
        return strtoupper((string) $countryCode).'|'.trim((string) $city);
    }

    public function key(): string
    {
        return self::keyFor($this->country_code, $this->city);
    }

    public function isResolved(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
