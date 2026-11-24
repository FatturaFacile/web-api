<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\Reservation;

/**
 * @extends Factory<Reservation>
 */
final class HospitalityReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(20, 0);

        return [
            'tenant_id' => Tenant::factory(),
            'guest_name' => fake()->name(),
            'guest_phone' => fake()->phoneNumber(),
            'party_size' => fake()->numberBetween(1, 6),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(90),
            'status' => 'booked',
        ];
    }
}
