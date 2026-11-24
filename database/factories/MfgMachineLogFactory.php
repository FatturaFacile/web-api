<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgMachineLog;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

/**
 * @extends Factory<MfgMachineLog>
 */
final class MfgMachineLogFactory extends Factory
{
    protected $model = MfgMachineLog::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'execution_id' => MfgShopFloorExecution::factory(),
            'machine_id' => (string) Str::uuid7(),
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'run_minutes' => 60.00,
            'downtime_minutes' => 0.00,
            'hourly_rate' => 45.0000,
            'machine_cost' => 45.0000,
        ];
    }
}
