<?php

namespace Database\Factories;

use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    protected $model = Scan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $country = fake()->randomElement(array_keys(config('countries')));
        $moment = fake()->dateTimeBetween('-1 week');

        return [
            'country_code' => $country,
            'observed_on' => $moment->format('Y-m-d'),
            'observed_hour' => (int) $moment->format('G'),
            'observed_minute' => (int) $moment->format('i'),
            'observed_second' => (int) $moment->format('s'),
            'base_query' => 'country:"'.$country.'"',
            'total_reported' => fake()->numberBetween(20, 500),
            'unique_hosts' => 0,
            'requests_used' => fake()->numberBetween(1, 30),
            'max_requests' => 30,
            'status' => Scan::STATUS_COMPLETED,
            'started_at' => $moment,
            'finished_at' => $moment,
        ];
    }

    /** Un scan encore confie a la file. */
    public function running(): static
    {
        return $this->state(fn () => [
            'status' => Scan::STATUS_RUNNING,
            'requests_used' => 0,
            'finished_at' => null,
        ]);
    }
}
