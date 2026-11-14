<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TravelExpense\Models\TravelLeg;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * @extends Factory<TravelLeg>
 */
final class TravelLegFactory extends Factory
{
    protected $model = TravelLeg::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'trip_id' => TravelTrip::factory(),
            'sequence' => 1,
            'leg_type' => fake()->randomElement(['flight', 'train', 'car']),
            'from_location' => fake()->city(),
            'to_location' => fake()->city(),
            'booking_status' => 'planned',
            'booked_cost' => '100.00',
            'currency' => 'EUR',
        ];
    }
}
