<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

/**
 * @extends Factory<MfgLaborTimeLog>
 */
final class MfgLaborTimeLogFactory extends Factory
{
    protected $model = MfgLaborTimeLog::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'execution_id' => MfgShopFloorExecution::factory(),
            'operator_id' => (string) Str::uuid7(),
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'duration_minutes' => 60.00,
            'hourly_rate' => 25.0000,
            'labor_cost' => 25.0000,
        ];
    }
}
