<?php

namespace App\Models;

use App\Models\Concerns\HasLabelledStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request sent to Shodan during a scan, and what we decided to do with it.
 *
 * This is the piece that makes working around the ceiling legible: unfolding
 * the steps shows "39 results, too many for 2 pages -> split by port ->
 * port:8080 yields 4 results, harvest them".
 *
 * @property-read string $state_label
 */
class ScanStep extends Model
{
    use HasFactory;
    use HasLabelledStatus;

    /** The sub-query fitted under the ceiling: its results are stored. */
    public const DECISION_HARVESTED = 'harvested';

    /** Too many results: split again on one more facet. */
    public const DECISION_SPLIT = 'split';

    /** Too many results and no facet left to split on. */
    public const DECISION_ABANDONED = 'abandoned';

    /** The run's request budget ran out. */
    public const DECISION_BUDGET_EXHAUSTED = 'budget_exhausted';

    /** Shodan answered with an error, or a page we could not read. */
    public const DECISION_FAILED = 'failed';

    /** @var array<string, string> */
    public const DECISION_LABELS = [
        self::DECISION_HARVESTED => 'moissonné',
        self::DECISION_SPLIT => 'découpé',
        self::DECISION_ABANDONED => 'abandonné',
        self::DECISION_BUDGET_EXHAUSTED => 'budget épuisé',
        self::DECISION_FAILED => 'échec',
    ];

    protected $fillable = [
        'scan_id', 'position', 'depth', 'query', 'applied_filter',
        'total_results', 'harvested', 'new_hosts', 'decision', 'note',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'depth' => 'integer',
            'total_results' => 'integer',
            'harvested' => 'integer',
            'new_hosts' => 'integer',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    protected function stateColumn(): string
    {
        return 'decision';
    }

    /** @return array<string, string> */
    protected function stateLabels(): array
    {
        return self::DECISION_LABELS;
    }
}
