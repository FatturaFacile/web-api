<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceActivity;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

/**
 * @extends Factory<MaintenanceActivity>
 */
final class MaintenanceActivityFactory extends Factory
{
    protected $model = MaintenanceActivity::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'company_id' => function (array $attrs) {
                return $attrs['tenant_id'];
            },
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'work_order_id' => function (array $attrs) {
                return MaintenanceWorkOrder::factory()->create([
                    'tenant_id' => $attrs['tenant_id'],
                    'asset_id' => $attrs['asset_id'],
                ])->id;
            },
            'activity_code' => 'ACT-'.strtoupper(Str::random(6)),
            'activity_type' => 'inspection',
            'description' => 'Spindle bearing alignment and vibration check',
            'duration_hours' => 2.50,
            'labor_cost' => 175.00,
            'spare_parts_cost' => 80.00,
            'spare_parts_consumed' => [['sku' => 'BRG-502', 'qty' => 2]],
            'status' => 'completed',
            'completed_at' => now(),
        ];
    }
}
