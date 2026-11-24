<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 5000, 120000);
        $salvage = round($cost * 0.05, 2);
        $base = round($cost - $salvage, 2);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'category_id' => function (array $attrs) {
                return AssetCategory::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'asset_number' => 'AST-'.strtoupper(Str::random(8)),
            'barcode_tag' => 'TAG-'.strtoupper(Str::random(8)),
            'serial_number' => 'SN-'.strtoupper(Str::random(10)),
            'name' => 'CNC Milling Center 5-Axis',
            'description' => 'High-precision industrial CNC machining center',
            'status' => 'registered',
            'condition' => 'new',
            'acquisition_type' => 'purchase',
            'acquisition_date' => date('Y-m-d'),
            'currency' => 'EUR',
            'acquisition_cost' => $cost,
            'capitalized_cost' => 0.00,
            'salvage_value' => $salvage,
            'depreciable_base' => $base,
            'accumulated_depreciation' => 0.00,
            'impairment_loss_total' => 0.00,
            'revaluation_surplus_total' => 0.00,
            'carrying_amount' => $cost,
            'replacement_cost' => $cost,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
            'useful_life_months' => 60,
            'depreciation_rate_percent' => 20.00,
            'total_expected_units' => 10000.00,
            'consumed_units' => 0.00,
            'is_depreciable' => true,
            'is_fully_depreciated' => false,
            'approval_status' => 'not_required',
            'is_locked' => false,
            'specifications' => ['power_kw' => 45, 'manufacturer' => 'DMG MORI'],
            'metadata' => ['source' => 'factory'],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'capitalized_cost' => 0.00,
        ]);
    }

    public function capitalized(): static
    {
        return $this->state(function (array $attributes) {
            $cost = (float) ($attributes['acquisition_cost'] ?? 24000.00);
            $salvage = (float) ($attributes['salvage_value'] ?? 0.00);

            return [
                'status' => 'capitalized',
                'capitalization_date' => date('Y-m-d'),
                'in_service_date' => date('Y-m-d'),
                'capitalized_cost' => $cost,
                'depreciable_base' => round($cost - $salvage, 2),
                'carrying_amount' => $cost,
                'approval_status' => 'approved',
            ];
        });
    }

    public function active(): static
    {
        return $this->capitalized()->state(fn (array $attributes) => [
            'status' => 'active',
            'commissioning_date' => date('Y-m-d'),
        ]);
    }

    public function maintenance(): static
    {
        return $this->capitalized()->state(fn (array $attributes) => [
            'status' => 'under_maintenance',
            'condition' => 'fair',
        ]);
    }

    public function revalued(): static
    {
        return $this->capitalized()->state(function (array $attributes) {
            $cost = (float) ($attributes['acquisition_cost'] ?? 24000.00);
            $surplus = round($cost * 0.20, 2);

            return [
                'status' => 'revalued',
                'revaluation_surplus_total' => $surplus,
                'carrying_amount' => round($cost + $surplus, 2),
            ];
        });
    }

    public function impaired(): static
    {
        return $this->capitalized()->state(function (array $attributes) {
            $cost = (float) ($attributes['acquisition_cost'] ?? 24000.00);
            $loss = round($cost * 0.25, 2);

            return [
                'status' => 'impaired',
                'condition' => 'damaged',
                'impairment_loss_total' => $loss,
                'carrying_amount' => round($cost - $loss, 2),
            ];
        });
    }

    public function disposed(): static
    {
        return $this->capitalized()->state(fn (array $attributes) => [
            'status' => 'disposed',
            'condition' => 'decommissioned',
            'disposal_date' => date('Y-m-d'),
            'carrying_amount' => 0.00,
            'is_depreciable' => false,
        ]);
    }

    public function sold(): static
    {
        return $this->capitalized()->state(fn (array $attributes) => [
            'status' => 'sold',
            'condition' => 'decommissioned',
            'disposal_date' => date('Y-m-d'),
            'carrying_amount' => 0.00,
            'is_depreciable' => false,
        ]);
    }

    public function retired(): static
    {
        return $this->capitalized()->state(fn (array $attributes) => [
            'status' => 'retired',
            'condition' => 'decommissioned',
            'retirement_date' => date('Y-m-d'),
            'carrying_amount' => 0.00,
            'is_depreciable' => false,
        ]);
    }
}
