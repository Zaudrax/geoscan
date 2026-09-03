<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A machine identified by its IP address.
 *
 * Deliberately almost empty: the host is the stable identity, and everything
 * that changes over time lives in its snapshots. That split is the heart of
 * step 3 of the assignment.
 */
class Host extends Model
{
    use HasFactory;

    protected $fillable = ['ip'];

    /** A host owns several snapshots, newest first. */
    public function snapshots(): HasMany
    {
        return $this->hasMany(HostSnapshot::class)->orderByDesc('fetched_at');
    }

    /** Shortcut to the most recent known snapshot. */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(HostSnapshot::class)->latestOfMany('fetched_at');
    }

    /**
     * Route model binding uses the IP, not the auto-increment id.
     *
     * An IP is what a user types and what a URL should show; exposing internal
     * ids in /hotes/{id} would be both uglier and less useful.
     */
    public function getRouteKeyName(): string
    {
        return 'ip';
    }
}
