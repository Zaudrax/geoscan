<?php

namespace App\Services\Exposure;

/**
 * The exposure verdict for a service or a host.
 *
 * The reported level is that of the MOST SEVERE finding, never a sum: ten
 * trivia do not make an emergency, and adding up points would manufacture false
 * precision.
 */
readonly class ExposureReport
{
    /** @param  list<Finding>  $findings */
    public function __construct(public array $findings) {}

    /** A verdict with nothing to report. */
    public static function empty(): self
    {
        return new self([]);
    }

    /** The most severe finding, or null when nothing was flagged. */
    public function worst(): ?Finding
    {
        $sorted = $this->sorted();

        return $sorted[0] ?? null;
    }

    /** The reported level key, or null when there is nothing to report. */
    public function level(): ?string
    {
        return $this->worst()?->level;
    }

    /** The reported level, ready to display. */
    public function levelLabel(): string
    {
        return $this->worst()?->levelLabel() ?? 'Rien a signaler';
    }

    /** Most severe first: the display order. */
    public function sorted(): array
    {
        $findings = $this->findings;

        usort($findings, fn (Finding $a, Finding $b) => $b->weight() <=> $a->weight());

        return $findings;
    }

    /** Whether anything rose above purely informational noise. */
    public function isNotable(): bool
    {
        return ($this->worst()?->weight() ?? 0) > 0;
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }
}
