<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetTransfer>
 */
class AssetTransferFactory extends Factory
{
    protected $model = AssetTransfer::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'transfer_number' => 'TRF-'.strtoupper(Str::random(8)),
            'transfer_date' => date('Y-m-d'),
            'status' => 'completed',
            'is_locked' => false,
            'reason' => 'Production line relocation',
            'completed_at' => now(),
        ];
    }
}
