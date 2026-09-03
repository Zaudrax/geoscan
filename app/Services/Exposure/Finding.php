<?php

namespace App\Services\Exposure;

/**
 * One exposure finding: what we saw, and why it matters.
 *
 * The most important field is not the level but the WHY. A tool that prints
 * "high risk" without justifying it teaches nobody anything and cannot be
 * argued with; we want the opposite.
 */
readonly class Finding
{
    public function __construct(
        public string $level,
        public string $title,
        public string $why,
        public ?string $detail = null,
    ) {}

    /** The level's weight, used to compare two findings. */
    public function weight(): int
    {
        return (int) config("exposure.levels.{$this->level}.weight", 0);
    }

    /** The level's display label, e.g. "critique" -> "Critique". */
    public function levelLabel(): string
    {
        return (string) config("exposure.levels.{$this->level}.label", $this->level);
    }
}
