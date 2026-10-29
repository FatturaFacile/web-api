<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetCategory;

/**
 * @extends Factory<AssetCategory>
 */
final class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CAT-'.strtoupper(Str::random(6)),
            'name' => fake()->words(2, true),
            'asset_type' => 'tangible',
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
            'useful_life_months' => 60,
            'statutory_rate_percent' => 20.00,
            'salvage_value_percent' => 5.00,
            'gl_asset_account_code' => '1600',
            'gl_accumulated_depreciation_code' => '1650',
            'gl_depreciation_expense_code' => '6400',
            'gl_impairment_account_code' => '6450',
            'gl_revaluation_reserve_code' => '3300',
            'gl_disposal_gain_loss_code' => '7500',
            'gl_clearing_account_code' => '2150',
            'is_depreciable' => true,
            'is_active' => true,
            'translations' => [
                'it' => 'Impianti e Macchinari',
                'en' => 'Plant and Machinery',
                'de' => 'Technische Anlagen und Maschinen',
                'fr' => 'Installations techniques et machines',
            ],
        ];
    }
}
