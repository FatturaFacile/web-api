<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<DepreciationSchedule>
 */
final class DepreciationScheduleFactory extends Factory
{
    protected $model = DepreciationSchedule::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'ledger_type' => 'statutory',
            'period_code' => date('Y-m'),
            'period_type' => 'monthly',
            'depreciation_method' => 'straight_line',
            'opening_book_value' => 24000.00,
            'depreciation_amount' => 400.00,
            'closing_book_value' => 23600.00,
            'accumulated_depreciation' => 400.00,
            'units_produced' => 0.00,
            'status' => 'posted',
            'calculated_at' => now(),
            'posted_at' => now(),
        ];
    }
}
