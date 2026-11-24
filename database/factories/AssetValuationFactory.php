<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetValuation>
 */
class AssetValuationFactory extends Factory
{
    protected $model = AssetValuation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'valuation_number' => 'VAL-'.strtoupper(Str::random(8)),
            'valuation_type' => 'revaluation',
            'valuation_date' => date('Y-m-d'),
            'carrying_amount_before' => 20000.00,
            'fair_value' => 25000.00,
            'value_in_use' => 24000.00,
            'recoverable_amount' => 25000.00,
            'adjustment_amount' => 5000.00,
            'revaluation_reserve_delta' => 5000.00,
            'impairment_loss_amount' => 0.00,
            'carrying_amount_after' => 25000.00,
            'status' => 'posted',
            'is_locked' => false,
            'appraiser_name' => 'Independent Certified Appraisers Ltd',
        ];
    }
}
