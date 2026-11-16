<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgEmissionFactor;

/**
 * @extends Factory<EsgActivityRecord>
 */
final class EsgActivityRecordFactory extends Factory
{
    protected $model = EsgActivityRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'emission_factor_id' => static fn (array $attributes): string => (string) EsgEmissionFactor::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'activity_date' => now()->toDateString(),
            'quantity' => '1000.0000',
            'unit' => 'kWh',
            'activity_type' => 'electricity_grid',
            'scope' => 2,
            'category' => 'purchased_electricity',
            'factor_code' => 'F-TEST',
            'factor_version' => 1,
            'factor_kg_co2e_per_unit' => '0.25000000',
            'factor_source' => 'Test source',
            'co2e_kg' => '250.0000',
            'data_quality' => 'measured',
        ];
    }
}
