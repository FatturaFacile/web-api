<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityMeterReading;

/**
 * @extends Factory<FacilityMeterReading>
 */
final class FacilityMeterReadingFactory extends Factory
{
    protected $model = FacilityMeterReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meter_id' => static fn (array $attributes): string => (string) FacilityMeter::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'read_on' => now()->toDateString(),
            'value' => '1000.000',
            'consumption' => '0.000',
            'reading_type' => 'regular',
        ];
    }
}
