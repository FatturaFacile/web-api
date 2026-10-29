<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenancePlan;

/**
 * @extends Factory<MaintenancePlan>
 */
final class MaintenancePlanFactory extends Factory
{
    protected $model = MaintenancePlan::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'plan_code' => 'MPLAN-'.strtoupper(Str::random(6)),
            'name' => 'Quarterly Preventive Spindle & Hydraulics Inspection',
            'maintenance_type' => 'preventive',
            'frequency_days' => 90,
            'estimated_duration_hours' => 4.00,
            'estimated_cost' => 650.00,
            'next_due_date' => date('Y-m-d', strtotime('+90 days')),
            'is_active' => true,
            'checklist' => ['Check hydraulic pressure', 'Inspect spindle bearings', 'Replace coolant filter'],
        ];
    }
}
