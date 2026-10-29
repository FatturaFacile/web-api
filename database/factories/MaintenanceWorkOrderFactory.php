<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

/**
 * @extends Factory<MaintenanceWorkOrder>
 */
class MaintenanceWorkOrderFactory extends Factory
{
    protected $model = MaintenanceWorkOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'work_order_number' => 'MWO-'.strtoupper(Str::random(8)),
            'maintenance_type' => 'preventive',
            'priority' => 'medium',
            'status' => 'scheduled',
            'scheduled_date' => date('Y-m-d'),
            'downtime_hours' => 2.50,
            'labor_cost' => 300.00,
            'parts_cost' => 150.00,
            'external_service_cost' => 0.00,
            'total_cost' => 450.00,
            'capitalize_as_upgrade' => false,
            'is_locked' => false,
        ];
    }
}
