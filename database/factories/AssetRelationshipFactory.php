<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetRelationship;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetRelationship>
 */
final class AssetRelationshipFactory extends Factory
{
    protected $model = AssetRelationship::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'source_asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'target_asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'relationship_type' => 'depends_on',
            'is_critical_dependency' => true,
        ];
    }
}
