<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * @extends Factory<MfgWorkCenter>
 */
final class MfgWorkCenterFactory extends Factory
{
    protected $model = MfgWorkCenter::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_center_code' => 'WC-'.strtoupper((string) Str::random(6)),
            'name' => fake()->words(2, true).' Center',
            'department_code' => 'PROD',
            'capacity_hours_per_day' => 8.00,
            'efficiency_rate' => 100.00,
            'availability_rate' => 100.00,
            'hourly_cost_rate' => fake()->randomFloat(4, 20, 60),
            'hourly_overhead_rate' => fake()->randomFloat(4, 10, 30),
            'status' => 'active',
        ];
    }
}
