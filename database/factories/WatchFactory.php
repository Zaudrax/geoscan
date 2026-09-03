<?php

namespace Database\Factories;

use App\Models\Watch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Watch>
 */
class WatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => 'Webcams '.fake()->word(),
            'country_code' => 'SE',
            'base_term' => '"Server: yawcam"',
            'interval_hours' => 24,
            'is_active' => true,
            'last_run_at' => null,
        ];
    }

    /** A watch that has just run, and is therefore not due. */
    public function justRan(): static
    {
        return $this->state(fn () => ['last_run_at' => now()]);
    }

    /** A suspended watch: the scheduler must skip it entirely. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
