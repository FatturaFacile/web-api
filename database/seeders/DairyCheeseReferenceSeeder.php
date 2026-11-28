<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;
use Modules\DairyCheeseManufacturing\Models\WeightLossCurve;

/**
 * Shared reference data of the dairy and cheese manufacturing vertical (Mod 298): the physiological weight loss curves of the
 * DOP / AOP cheeses (12, 24 and 36 months for Parmigiano Reggiano) and the milk quality pricing grid templates of Switzerland
 * (Gruyère AOP, Formaggio d'Alpe Ticinese DOP) and Italy (Parmigiano Reggiano DOP, Grana Padano DOP). Rows are global (no tenant,
 * read-only for the companies, who copy them into their own grids and curves), deterministic and idempotent.
 * The curves are typical industry values and the prices indicative starting points: each dairy sets its own.
 */
final class DairyCheeseReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->curves() as [$type, $name, $points]) {
            WeightLossCurve::updateOrCreateGlobal(['cheese_type' => $type, 'name' => $name], [
                'points' => array_map(static fn (array $p): array => ['age_months' => $p[0], 'loss_pct' => $p[1]], $points),
            ]);
        }

        foreach ($this->grids() as $grid) {
            PricingGrid::updateOrCreateGlobal(['name' => $grid['name']], $grid + ['valid_from' => '2026-01-01', 'valid_to' => null, 'is_active' => true]);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<array{0: float|int, 1: float|int}>}>
     */
    private function curves(): array
    {
        return [
            ['parmigiano_reggiano_dop', 'Parmigiano Reggiano DOP 12 / 24 / 36 months', [[1, 1.5], [6, 4.0], [12, 6.5], [18, 8.5], [24, 10.5], [30, 12.0], [36, 13.5]]],
            ['grana_padano_dop', 'Grana Padano DOP 9 - 36 months', [[1, 1.5], [9, 5.0], [12, 6.5], [16, 8.0], [20, 9.5], [24, 11.0], [36, 14.0]]],
            ['gruyere_aop', 'Gruyère AOP 5 - 24 months', [[1, 1.5], [3, 5.0], [5, 7.5], [9, 10.0], [12, 12.0], [18, 14.5], [24, 16.0]]],
            ['sbrinz_aop', 'Sbrinz AOP 6 - 36 months', [[1, 2.0], [6, 8.0], [12, 13.0], [18, 17.0], [24, 20.0], [36, 24.0]]],
            ['alpe_ticinese_dop', 'Formaggio d\'Alpe Ticinese DOP 1 - 12 months', [[1, 2.0], [2, 4.0], [3, 6.0], [6, 9.0], [12, 13.0]]],
            ['generic', 'Generic hard cheese 1 - 36 months', [[1, 2.0], [3, 5.0], [6, 8.0], [12, 12.0], [24, 17.0], [36, 20.0]]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grids(): array
    {
        return [
            [
                'name' => 'Parmigiano Reggiano DOP — IT milk quality grid (EUR/kg)', 'jurisdiction' => 'IT', 'cheese_type' => 'parmigiano_reggiano_dop',
                'base_price_per_kg' => 0.5800, 'cheese_milk_supplement_per_kg' => 0.0000, 'dop_premium_per_kg' => 0.0500,
                'tiers' => $this->tiers(fatLow: 3.40, fatHigh: 4.00, proteinLow: 3.10, proteinHigh: 3.40, scc: [200000, 400000], tbc: [30000, 100000], penalty: 0.50),
            ],
            [
                'name' => 'Grana Padano DOP — IT milk quality grid (EUR/kg)', 'jurisdiction' => 'IT', 'cheese_type' => 'grana_padano_dop',
                'base_price_per_kg' => 0.5600, 'cheese_milk_supplement_per_kg' => 0.0000, 'dop_premium_per_kg' => 0.0400,
                'tiers' => $this->tiers(fatLow: 3.40, fatHigh: 3.90, proteinLow: 3.10, proteinHigh: 3.35, scc: [200000, 400000], tbc: [30000, 100000], penalty: 0.50),
            ],
            [
                'name' => 'Gruyère AOP — CH Zulage für verkäste Milch (CHF/kg)', 'jurisdiction' => 'CH', 'cheese_type' => 'gruyere_aop',
                'base_price_per_kg' => 0.6800, 'cheese_milk_supplement_per_kg' => 0.1500, 'dop_premium_per_kg' => 0.0600,
                'tiers' => $this->tiers(fatLow: 3.60, fatHigh: 4.10, proteinLow: 3.20, proteinHigh: 3.50, scc: [150000, 350000], tbc: [20000, 80000], penalty: 0.60),
            ],
            [
                'name' => 'Formaggio d\'Alpe Ticinese DOP — CH supplemento latte trasformato (CHF/kg)', 'jurisdiction' => 'CH', 'cheese_type' => 'alpe_ticinese_dop',
                'base_price_per_kg' => 0.7200, 'cheese_milk_supplement_per_kg' => 0.1500, 'dop_premium_per_kg' => 0.1000,
                'tiers' => $this->tiers(fatLow: 3.50, fatHigh: 4.00, proteinLow: 3.10, proteinHigh: 3.40, scc: [150000, 350000], tbc: [20000, 80000], penalty: 0.60),
            ],
        ];
    }

    /**
     * Bonus and penalty tiers on fat, protein, somatic cells, bacterial count and inhibitors.
     *
     * @param  array{0: int, 1: int}  $scc  [bonus below, penalty from]
     * @param  array{0: int, 1: int}  $tbc  [bonus below, penalty from]
     * @return list<array{component: string, from_value: float|int|null, to_value: float|int|null, adjustment_per_kg: float}>
     */
    private function tiers(float $fatLow, float $fatHigh, float $proteinLow, float $proteinHigh, array $scc, array $tbc, float $penalty): array
    {
        return [
            ['component' => 'fat', 'from_value' => null, 'to_value' => $fatLow, 'adjustment_per_kg' => -0.0150],
            ['component' => 'fat', 'from_value' => $fatHigh, 'to_value' => null, 'adjustment_per_kg' => 0.0100],
            ['component' => 'protein', 'from_value' => null, 'to_value' => $proteinLow, 'adjustment_per_kg' => -0.0150],
            ['component' => 'protein', 'from_value' => $proteinHigh, 'to_value' => null, 'adjustment_per_kg' => 0.0120],
            ['component' => 'scc', 'from_value' => null, 'to_value' => $scc[0], 'adjustment_per_kg' => 0.0100],
            ['component' => 'scc', 'from_value' => $scc[1], 'to_value' => null, 'adjustment_per_kg' => -0.0300],
            ['component' => 'tbc', 'from_value' => null, 'to_value' => $tbc[0], 'adjustment_per_kg' => 0.0080],
            ['component' => 'tbc', 'from_value' => $tbc[1], 'to_value' => null, 'adjustment_per_kg' => -0.0300],
            ['component' => 'inhibitor', 'from_value' => 1, 'to_value' => null, 'adjustment_per_kg' => -$penalty],
        ];
    }
}
