<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityPlan;

/**
 * @extends Factory<MfgQualityCharacteristic>
 */
final class MfgQualityCharacteristicFactory extends Factory
{
    protected $model = MfgQualityCharacteristic::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'quality_plan_id' => MfgQualityPlan::factory(),
            'characteristic_name' => fake()->word().' Tolerance',
            'target_value' => 50.0000,
            'min_tolerance' => 49.5000,
            'max_tolerance' => 50.5000,
            'uom' => 'MM',
            'inspection_method' => 'Caliper measurement',
            'is_critical' => false,
        ];
    }
}
