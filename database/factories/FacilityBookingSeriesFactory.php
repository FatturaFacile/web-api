<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBookingSeries;
use Modules\Facility\Models\FacilitySpace;

/**
 * @extends Factory<FacilityBookingSeries>
 */
final class FacilityBookingSeriesFactory extends Factory
{
    protected $model = FacilityBookingSeries::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'space_id' => static fn (array $attributes): string => (string) FacilitySpace::factory()->desk()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'recurrence' => 'weekly',
            'weekdays' => [1, 3],
            'start_date' => now()->addDay()->toDateString(),
            'until_date' => now()->addWeeks(4)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'active',
            'occurrences' => 0,
        ];
    }
}
