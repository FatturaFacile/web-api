<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * @extends Factory<MfgOperation>
 */
final class MfgOperationFactory extends Factory
{
    protected $model = MfgOperation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'routing_id' => MfgRouting::factory(),
            'sequence' => 10,
            'operation_code' => 'OP-'.strtoupper((string) Str::random(6)),
            'name' => fake()->words(2, true).' Operation',
            'work_center_id' => MfgWorkCenter::factory(),
            'setup_time_minutes' => fake()->randomFloat(2, 5, 30),
            'run_time_minutes_per_unit' => fake()->randomFloat(2, 2, 20),
            'labor_time_minutes_per_unit' => fake()->randomFloat(2, 2, 20),
            'standard_labor_rate' => 25.0000,
            'standard_machine_rate' => 45.0000,
        ];
    }
}
