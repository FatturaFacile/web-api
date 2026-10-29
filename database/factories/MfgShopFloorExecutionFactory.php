<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * @extends Factory<MfgShopFloorExecution>
 */
final class MfgShopFloorExecutionFactory extends Factory
{
    protected $model = MfgShopFloorExecution::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'execution_number' => 'JBC-'.strtoupper((string) Str::random(8)),
            'production_order_id' => MfgProductionOrder::factory(),
            'production_order_operation_id' => MfgProductionOrderOperation::factory(),
            'work_center_id' => MfgWorkCenter::factory(),
            'status' => 'active',
            'good_units' => 10.0000,
            'scrap_units' => 0.0000,
            'started_at' => now(),
        ];
    }
}
