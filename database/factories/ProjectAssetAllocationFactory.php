<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\ProjectAssetAllocation;

/**
 * @extends Factory<ProjectAssetAllocation>
 */
final class ProjectAssetAllocationFactory extends Factory
{
    protected $model = ProjectAssetAllocation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'project_id' => (string) Str::uuid7(),
            'allocated_from' => date('Y-m-d'),
            'hourly_rate' => 85.00,
            'daily_rate' => 600.00,
            'utilized_hours' => 40.00,
            'utilized_days' => 5.00,
            'attributed_cost' => 6400.00,
            'status' => 'active',
        ];
    }
}
