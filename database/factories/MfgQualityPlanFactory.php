<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgQualityPlan;

/**
 * @extends Factory<MfgQualityPlan>
 */
final class MfgQualityPlanFactory extends Factory
{
    protected $model = MfgQualityPlan::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'plan_code' => 'QP-'.strtoupper((string) Str::random(6)),
            'name' => fake()->words(3, true).' Plan',
            'inspection_type' => fake()->randomElement(['incoming', 'in_process', 'final']),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
