<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Maps a stored status/decision/outcome key to the label shown in the UI.
 *
 * Three models store a machine readable state alongside a human readable
 * translation of it. Rather than repeating the same lookup in each, they
 * declare the column and the map, and get an accessor for free.
 *
 * Implementing models must define:
 *   - `stateColumn()`  the attribute holding the raw key
 *   - `stateLabels()`  the key => label map
 *
 * @property-read string $state_label
 */
trait HasLabelledStatus
{
    /** The attribute holding the raw, machine readable state. */
    abstract protected function stateColumn(): string;

    /**
     * The raw state => human label map.
     *
     * @return array<string, string>
     */
    abstract protected function stateLabels(): array;

    /**
     * Falls back to the raw key when it has no label, so a state added to the
     * database but not yet to the map still renders something meaningful.
     */
    protected function stateLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $state = (string) $this->{$this->stateColumn()};

            return $this->stateLabels()[$state] ?? $state;
        });
    }
}
