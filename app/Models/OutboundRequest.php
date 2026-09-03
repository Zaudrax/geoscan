<?php

namespace App\Models;

use App\Models\Concerns\HasLabelledStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbound request, recorded as it actually happened.
 *
 * Immutable by convention, like host snapshots: a log you can rewrite proves
 * nothing. Rows capture intent and outcome only, never content -- neither the
 * HTML received nor the cookies sent.
 *
 * @property-read string $state_label
 */
class OutboundRequest extends Model
{
    use HasLabelledStatus;

    /** The request went out and the server answered normally. */
    public const OUTCOME_SENT = 'sent';

    /** The server answered, but with an error status. */
    public const OUTCOME_FAILED = 'failed';

    /** Nothing left the process: robots.txt disallowed the path. */
    public const OUTCOME_BLOCKED_BY_ROBOTS = 'blocked_by_robots';

    /** Nothing came back: timeout, DNS failure, dropped connection. */
    public const OUTCOME_UNREACHABLE = 'unreachable';

    /** @var array<string, string> */
    public const OUTCOME_LABELS = [
        self::OUTCOME_SENT => 'envoyée',
        self::OUTCOME_FAILED => 'erreur serveur',
        self::OUTCOME_BLOCKED_BY_ROBOTS => 'bloquée par robots.txt',
        self::OUTCOME_UNREACHABLE => 'injoignable',
    ];

    protected $fillable = [
        'service', 'path', 'query', 'status', 'outcome', 'note',
        'waited_seconds', 'authenticated', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'authenticated' => 'boolean',
            'waited_seconds' => 'float',
            'status' => 'integer',
        ];
    }

    protected function stateColumn(): string
    {
        return 'outcome';
    }

    /** @return array<string, string> */
    protected function stateLabels(): array
    {
        return self::OUTCOME_LABELS;
    }

    /** A request that never left: the robots.txt guard bit. */
    public function wasBlocked(): bool
    {
        return $this->outcome === self::OUTCOME_BLOCKED_BY_ROBOTS;
    }
}
