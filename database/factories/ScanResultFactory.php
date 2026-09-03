<?php

namespace Database\Factories;

use App\Models\Host;
use App\Models\Scan;
use App\Models\ScanResult;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ScanResult>
 */
class ScanResultFactory extends Factory
{
    protected $model = ScanResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ip = fake()->unique()->ipv4();
        $observedAt = Carbon::instance(fake()->dateTimeBetween('-1 week'));

        return [
            'scan_id' => Scan::factory(),
            'host_id' => fn () => Host::firstOrCreate(['ip' => $ip])->id,
            'ip' => $ip,
            'port' => fake()->randomElement([80, 443, 8080, 8443, 22]),
            'country_code' => 'PL',
            'country' => 'Poland',
            'city' => fake()->randomElement(['Warsaw', 'Krakow', 'Gdansk']),
            'organization' => fake()->company(),
            'product' => null,
            'hostnames' => [fake()->domainName()],
            'tags' => fake()->randomElements(['cloud', 'self-signed', 'cdn'], 2),
            'technologies' => ['Nginx'],
            'banner' => 'HTTP/1.1 200 OK',
            'matched_query' => 'country:"PL"',
            ...ScanResult::timeParts($observedAt),
        ];
    }

    /** A result bound to a specific scan and IP. */
    public function forHost(Scan $scan, string $ip, ?int $port = null): static
    {
        return $this->state(fn () => [
            'scan_id' => $scan->id,
            'host_id' => Host::firstOrCreate(['ip' => $ip])->id,
            'ip' => $ip,
            'port' => $port ?? 80,
        ]);
    }
}
