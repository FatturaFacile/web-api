<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * @extends Factory<MfgMachineCenter>
 */
final class MfgMachineCenterFactory extends Factory
{
    protected $model = MfgMachineCenter::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'machine_code' => 'MC-'.strtoupper((string) Str::random(6)),
            'name' => fake()->words(2, true).' Machine',
            'work_center_id' => MfgWorkCenter::factory(),
            'hourly_rate' => fake()->randomFloat(4, 30, 80),
            'power_kw' => fake()->randomFloat(2, 5, 50),
            'status' => 'active',
        ];
    }
}
