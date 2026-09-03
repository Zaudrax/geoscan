<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One harvested observation: an (IP, port) pair as it appeared in a Shodan
 * result page, together with the sub-query that surfaced it.
 *
 * Once stored, nothing here ever goes back to Shodan. The scan view's filters
 * work exclusively against these rows, which makes them instant and allows
 * cross-referencing that Shodan itself does not offer to a free account.
 */
class ScanResult extends Model
{
    use HasFactory;

    /**
     * Dimensions offered as filters in the scan view's left column.
     * The key doubles as the query string parameter name.
     *
     * @var array<string, string>
     */
    public const FACET_DIMENSIONS = [
        'port' => 'Ports',
        'city' => 'Villes',
        'organization' => 'Organisations',
        'product' => 'Produits',
        'tag' => 'Tags',
        'date' => "Dates d'observation",
        'hour' => 'Heures',
        'second' => 'Secondes',
    ];

    protected $fillable = [
        'scan_id', 'host_id', 'ip', 'port', 'country_code', 'country', 'city',
        'organization', 'product', 'hostnames', 'tags', 'technologies',
        'banner', 'observed_at', 'observed_date', 'observed_hour',
        'observed_minute', 'observed_second', 'matched_query',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'hostnames' => 'array',
            'tags' => 'array',
            'technologies' => 'array',
            'observed_at' => 'datetime',
            'observed_date' => 'date',
            'observed_hour' => 'integer',
            'observed_minute' => 'integer',
            'observed_second' => 'integer',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * The identity of a service, and therefore the de-duplication key.
     *
     * An IP alone is not enough: the same machine exposing two ports is two
     * distinct services. Both the enumerator (de-duplicating across slices)
     * and watches (spotting newcomers between passes) key on this, so the
     * definition lives in one place.
     */
    public function serviceKey(): string
    {
        return $this->ip.':'.$this->port;
    }

    /**
     * Explodes a timestamp into its indexed parts.
     *
     * Called on insert: this is what reduces the scan view's hour/minute/second
     * filters to plain integer comparisons instead of date arithmetic.
     *
     * @return array{observed_at: ?Carbon, observed_date: ?string, observed_hour: ?int, observed_minute: ?int, observed_second: ?int}
     */
    public static function timeParts(?Carbon $moment): array
    {
        return [
            'observed_at' => $moment,
            'observed_date' => $moment?->toDateString(),
            'observed_hour' => $moment?->hour,
            'observed_minute' => $moment?->minute,
            'observed_second' => $moment?->second,
        ];
    }

    /**
     * Applies the scan view's filters.
     *
     * Any key that is absent, empty or null is ignored: the array comes
     * straight from the query string, and an empty `?port=` must not empty the
     * result list along with it.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $filters = collect($filters)
            ->map(fn (mixed $value) => is_scalar($value) ? trim((string) $value) : null)
            ->reject(fn (?string $value) => blank($value));

        $exact = ['city' => 'city', 'organization' => 'organization', 'product' => 'product'];
        $integer = ['port' => 'port', 'hour' => 'observed_hour', 'minute' => 'observed_minute', 'second' => 'observed_second'];

        foreach ($exact as $key => $column) {
            $query->when($filters->get($key), fn (Builder $q, string $value) => $q->where($column, $value));
        }

        foreach ($integer as $key => $column) {
            $query->when($filters->get($key), fn (Builder $q, string $value) => $q->where($column, (int) $value));
        }

        return $query
            ->when($filters->get('country'), fn (Builder $q, string $code) => $q->where('country_code', strtoupper($code)))
            ->when($filters->get('tag'), fn (Builder $q, string $tag) => $q->whereJsonContains('tags', $tag))
            ->when($filters->get('date'), fn (Builder $q, string $date) => $q->whereDate('observed_date', $date))
            ->when($filters->get('q'), fn (Builder $q, string $term) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereLike('ip', "%{$term}%")
                    ->orWhereLike('banner', "%{$term}%")
                    ->orWhereLike('hostnames', "%{$term}%")
                    ->orWhereLike('organization', "%{$term}%")
            ));
    }

    /**
     * Builds the left column's filters from the results themselves.
     *
     * This is the substantive difference with Shodan's own rankings: those
     * describe a dataset we are not allowed to read, these describe the one we
     * hold. They are therefore clickable without ever going back on the wire.
     *
     * Computed in PHP rather than SQL: a scan holds tens to hundreds of rows,
     * the collection is already loaded for the map, and tags are a JSON array
     * that no portable GROUP BY can unfold.
     *
     * @param  Collection<int, self>  $results
     * @return array<string, list<array{value: string, label: string, count: int}>>
     */
    public static function facetsFrom(Collection $results, int $perDimension = 12): array
    {
        return collect(self::FACET_DIMENSIONS)
            ->keys()
            ->mapWithKeys(fn (string $dimension) => [
                $dimension => $results
                    ->flatMap(fn (self $result) => $result->facetValues($dimension))
                    ->countBy()
                    ->sortDesc()
                    ->take($perDimension)
                    ->map(fn (int $count, string $value) => [
                        'value' => $value,
                        'label' => self::facetLabel($dimension, $value),
                        'count' => $count,
                    ])
                    ->values()
                    ->all(),
            ])
            ->reject(fn (array $values) => $values === [])
            ->all();
    }

    /**
     * The values this result takes on a dimension. A list, because one result
     * can carry several tags.
     *
     * @return list<string>
     */
    private function facetValues(string $dimension): array
    {
        $raw = match ($dimension) {
            'port' => $this->port,
            'city' => $this->city,
            'organization' => $this->organization,
            'product' => $this->product,
            'tag' => $this->tags ?? [],
            'date' => $this->observed_date?->toDateString(),
            'hour' => $this->observed_hour,
            'second' => $this->observed_second,
            default => null,
        };

        return collect(is_array($raw) ? $raw : [$raw])
            ->map(fn (mixed $value) => trim((string) $value))
            ->reject(fn (string $value) => $value === '')
            ->values()
            ->all();
    }

    /** Makes a facet value readable: 9 -> "09 h", 3 -> ":03". */
    private static function facetLabel(string $dimension, string $value): string
    {
        return match ($dimension) {
            'hour' => str_pad($value, 2, '0', STR_PAD_LEFT).' h',
            'second' => ':'.str_pad($value, 2, '0', STR_PAD_LEFT),
            'date' => Carbon::parse($value)->format('d/m/Y'),
            default => $value,
        };
    }

    /** Geolocation key: two results from the same city share it. */
    public function geoKey(): string
    {
        return GeoPoint::keyFor($this->country_code, $this->city);
    }

    /** The first known hostname, falling back to the IP. */
    public function displayName(): string
    {
        return $this->hostnames[0] ?? $this->ip;
    }
}
