<?php

namespace App\Models;

use App\Models\Concerns\ResolvesCountryName;
use App\Services\Shodan\ShodanQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved search replayed on a fixed interval.
 *
 * A scan answers "what is out there?". A watch answers "what is NEW out
 * there?", which is the question you actually ask when monitoring an attack
 * surface over time.
 *
 * It deliberately stores no results of its own: it owns scans, and novelty is
 * derived by comparing the two most recent ones. Copying results here would
 * create a second source of truth for the same facts.
 *
 * @property-read string $country_name
 */
class Watch extends Model
{
    use HasFactory;
    use ResolvesCountryName;

    protected $fillable = [
        'label', 'country_code', 'base_term', 'interval_hours', 'is_active', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'interval_hours' => 'integer',
        ];
    }

    /**
     * Newest first, so `$watch->scans->first()` is the current state and the
     * second entry is what we compare it against.
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class)->latest('started_at');
    }

    /** Watches the scheduler is allowed to consider. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this watch is ready to run again.
     *
     * Deliberately answered in PHP rather than SQL. Expressing "last run plus
     * N hours has passed" in a query needs a raw, driver specific date
     * expression, because the interval lives in a column rather than being a
     * constant. There are never many watches, so the portable and readable
     * option wins; callers pair it with `active()` and Collection::filter.
     *
     * A watch that has never run is due immediately, otherwise it would sit
     * idle for a full interval before its first pass for no reason.
     */
    public function isDue(): bool
    {
        return $this->last_run_at === null
            || $this->last_run_at->addHours($this->interval_hours)->isPast();
    }

    /** The Shodan query this watch replays on every pass. */
    public function shodanQuery(): string
    {
        return ShodanQuery::forCountryTerm($this->country_code, $this->base_term)->toString();
    }

    /**
     * Services visible on the latest pass but absent from the one before it.
     *
     * This is the whole point of the entity. Comparison is on the (IP, port)
     * pair: a machine we already knew that opens a second service is just as
     * much a change to the attack surface as a brand new machine.
     *
     * With fewer than two passes we report nothing. A first scan is a
     * baseline, not a discovery, and announcing "80 new services" on the very
     * first run would be actively misleading.
     *
     * @return Collection<int, ScanResult>
     */
    public function newcomers(): Collection
    {
        [$latest, $previous] = [$this->scans->get(0), $this->scans->get(1)];

        if (! $latest || ! $previous) {
            return new Collection;
        }

        $seenBefore = $previous->results()
            ->get(['ip', 'port'])
            ->map->serviceKey()
            ->flip();

        return $latest->results()
            ->get()
            ->reject(fn (ScanResult $result) => $seenBefore->has($result->serviceKey()))
            ->values();
    }
}
