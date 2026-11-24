<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetDisposal>
 */
final class AssetDisposalFactory extends Factory
{
    protected $model = AssetDisposal::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->disposed()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'disposal_number' => 'DSP-'.strtoupper(Str::random(8)),
            'disposal_type' => 'sale',
            'disposal_date' => date('Y-m-d'),
            'asset_cost_at_disposal' => 20000.00,
            'accumulated_depreciation_at_disposal' => 12000.00,
            'carrying_amount_at_disposal' => 8000.00,
            'proceeds_amount' => 10000.00,
            'disposal_costs' => 500.00,
            'gain_loss_amount' => 1500.00,
            'status' => 'completed',
            'is_locked' => false,
            'completed_at' => now(),
        ];
    }
}
