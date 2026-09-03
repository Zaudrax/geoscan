<?php

namespace App\Models;

use App\Models\Concerns\HasLabelledStatus;
use App\Models\Concerns\ResolvesCountryName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One enumeration campaign: "every Polish IP seen on 2026-09-01 at 09:13:03".
 *
 * How this differs from Search (step 6 of the assignment): a search is ONE page
 * scraped at a point in time. A scan is a TREE of sub-queries, each narrow
 * enough to fit under the 20 visible results ceiling, whose harvests are glued
 * back together without duplicates.
 *
 * @property-read string $country_name
 * @property-read string $state_label
 */
class Scan extends Model
{
    use HasFactory;
    use HasLabelledStatus;
    use ResolvesCountryName;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STATUS_FAILED = 'failed';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_RUNNING => 'en cours',
        self::STATUS_COMPLETED => 'terminé',
        self::STATUS_BUDGET_EXHAUSTED => 'budget épuisé',
        self::STATUS_FAILED => 'échec',
    ];

    protected $fillable = [
        'watch_id', 'country_code', 'base_term', 'observed_on', 'observed_hour', 'observed_minute',
        'observed_second', 'base_query', 'total_reported', 'unique_hosts',
        'requests_used', 'max_requests', 'status', 'failure_reason',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'observed_hour' => 'integer',
            'observed_minute' => 'integer',
            'observed_second' => 'integer',
            'total_reported' => 'integer',
            'unique_hosts' => 'integer',
            'requests_used' => 'integer',
            'max_requests' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected function stateColumn(): string
    {
        return 'status';
    }

    /** @return array<string, string> */
    protected function stateLabels(): array
    {
        return self::STATUS_LABELS;
    }

    /** The watch that launched this scan, absent when it was started by hand. */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(Watch::class);
    }

    /** The harvested observations, already de-duplicated on (IP, port). */
    public function results(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    /** The step by step trace of the splitting algorithm. */
    public function steps(): HasMany
    {
        return $this->hasMany(ScanStep::class)->orderBy('position');
    }

    /** The targeted instant, to the second. Null when the scan sweeps a minute. */
    public function observedAt(): ?Carbon
    {
        if ($this->observed_second === null) {
            return null;
        }

        return $this->observed_on->copy()->setTime(
            $this->observed_hour,
            $this->observed_minute,
            $this->observed_second,
        );
    }

    /**
     * "01/09/2026 09:13:03", or "01/09/2026 09:13:xx" when the second is being
     * swept, or the term itself when the scan started from a banner search.
     */
    public function windowLabel(): string
    {
        if (filled($this->base_term)) {
            return $this->base_term;
        }

        $second = $this->observed_second === null
            ? 'xx'
            : str_pad((string) $this->observed_second, 2, '0', STR_PAD_LEFT);

        return sprintf(
            '%s %02d:%02d:%s',
            $this->observed_on->format('d/m/Y'),
            $this->observed_hour,
            $this->observed_minute,
            $second,
        );
    }

    /**
     * The share of Shodan's announced results we actually retrieved.
     *
     * It rarely reaches 100% but can come close. The gap comes from facet
     * values Shodan does not list, from the request budget, and from Shodan's
     * own total being slightly optimistic.
     *
     * When nothing was announced, having found anything at all counts as full
     * coverage: there is no denominator to be short of.
     */
    public function coverage(): float
    {
        if ($this->total_reported <= 0) {
            return $this->unique_hosts > 0 ? 1.0 : 0.0;
        }

        return min(1.0, $this->unique_hosts / $this->total_reported);
    }

    /** The ceiling the enumeration works around: 20 consultable results. */
    public function visibleCeiling(): int
    {
        return (int) config('geoscan.enumeration.page_limit', 2)
            * (int) config('geoscan.enumeration.per_page', 10);
    }

    /** True when the scan genuinely went past what Shodan lets a visitor see. */
    public function beatTheCeiling(): bool
    {
        return $this->unique_hosts > $this->visibleCeiling();
    }
}
