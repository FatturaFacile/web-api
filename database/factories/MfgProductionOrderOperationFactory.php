<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * @extends Factory<MfgProductionOrderOperation>
 */
final class MfgProductionOrderOperationFactory extends Factory
{
    protected $model = MfgProductionOrderOperation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'production_order_id' => MfgProductionOrder::factory(),
            'sequence' => 10,
            'work_center_id' => MfgWorkCenter::factory(),
            'status' => 'pending',
            'planned_setup_minutes' => 15.00,
            'planned_run_minutes' => 60.00,
            'completed_quantity' => 0.0000,
            'scrapped_quantity' => 0.0000,
        ];
    }
}
