<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsTrip;
use Modules\Transportation\Models\TmsTripStop;

/**
 * @extends Factory<TmsTripStop>
 */
final class TmsTripStopFactory extends Factory
{
    protected $model = TmsTripStop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'trip_id' => TmsTrip::factory(),
            'sequence' => 1,
            'stop_type' => 'pickup',
            'location' => fake()->unique()->bothify('??-####'),
            'status' => 'pending',
        ];
    }
}
