<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetCustodian;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetCustodian>
 */
final class AssetCustodianFactory extends Factory
{
    protected $model = AssetCustodian::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'custodian_user_id' => User::factory(),
            'department' => 'Operations',
            'assigned_at' => now(),
            'condition_on_checkout' => 'good',
            'status' => 'active',
        ];
    }
}
