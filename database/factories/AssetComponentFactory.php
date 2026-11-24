<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetComponent>
 */
final class AssetComponentFactory extends Factory
{
    protected $model = AssetComponent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'parent_asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'component_code' => 'CMP-'.strtoupper(Str::random(6)),
            'name' => 'High-Speed Spindle Unit',
            'serial_number' => 'SN-CMP-'.strtoupper(Str::random(6)),
            'cost_allocation' => 4500.00,
            'useful_life_months' => 36,
            'condition' => 'new',
            'status' => 'active',
            'installed_date' => date('Y-m-d'),
        ];
    }
}
