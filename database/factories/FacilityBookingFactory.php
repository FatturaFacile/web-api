<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilitySpace;

/**
 * @extends Factory<FacilityBooking>
 */
final class FacilityBookingFactory extends Factory
{
    protected $model = FacilityBooking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'space_id' => static fn (array $attributes): string => (string) FacilitySpace::factory()->meetingRoom()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => fake()->sentence(3),
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'status' => 'booked',
        ];
    }
}
