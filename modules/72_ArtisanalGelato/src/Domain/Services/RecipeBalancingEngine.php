<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;

/**
 * Physico-chemical balancing of an ice cream, sorbet, semifreddo or ganache mix. Pure and deterministic: it reads ingredient
 * snapshots and grams and returns the indexes. Every index is normalised per 1,000 g of mix (the batch weight cancels out), so
 * with the mass fraction x_i = w_i / W:
 *
 *   POD = 10 * sum(x_i * POD_i)                         (sucrose = 100 per 100 g of ingredient)
 *   PAC = 10 * sum(x_i * PAC_i) + SLNG% * 10 * 0.54     (milk salts and lactose add 0.54 per gram of SLNG)
 *   SLNG max = (100 - total solids%) / 6.9              (lactose sandiness guardrail)
 *
 * The sums run on integer micro-units (grams and index points x 10^6) and are rounded half away from zero at 4 decimals only
 * when exposed, so adding or removing a line never leaves floating point residue in the stored values.
 *
 * The freezing curve uses the freezing point depression of a sucrose equivalent solution (1.86 K kg/mol, 342.3 g/mol): the
 * freezing point is Tf = -0.005434 * PAC * 1000 / water g, and the ice fraction of the freezable water at T < Tf is 1 - Tf/T.
 * The unfreezable (bound) water is estimated as 0.3 g per g of SLNG and of fibre.
 */
final class RecipeBalancingEngine
{
    public const float MILK_SALTS_FACTOR = 0.54;

    public const float LACTOSE_DIVISOR = 6.9;

    public const float CRYOSCOPIC_PER_SUCROSE_GRAM_PER_KG_WATER = 0.005434;

    public const float DEFAULT_MIX_DENSITY_KG_PER_L = 1.09;

    public const float BOUND_WATER_PER_GRAM = 0.3;

    /** @var list<int> */
    public const array CURVE_TEMPERATURES = [-10, -11, -12, -13, -14, -15, -16, -17, -18];

    /**
     * @param  list<array{grams: float|int|string, pod?: float|int|string, pac?: float|int|string, milk_fat_percent?: float|int|string, other_fat_percent?: float|int|string, slng_percent?: float|int|string, total_solids_percent?: float|int|string, water_percent?: float|int|string|null, fiber_percent?: float|int|string, cost_per_kg?: float|int|string}>  $lines
     * @param  array{overrun_percent?: float|int, mix_density?: float|int, serving_portion_grams?: float|int, tub_weight_grams?: float|int, bands?: array<string, array{0: float|int, 1: float|int}>}  $options
     * @return array<string, mixed>
     */
    public function analyze(array $lines, RecipeCategory $category, array $options = []): array
    {
        $totalGrams = 0;
        $sums = ['pod' => 0, 'pac' => 0, 'milk_fat' => 0, 'other_fat' => 0, 'slng' => 0, 'solids' => 0, 'water' => 0, 'fiber' => 0, 'cost' => 0];

        foreach ($lines as $line) {
            $grams = self::micro($line['grams']);

            if ($grams < 0) {
                throw new InvalidArgumentException('Ingredient weights cannot be negative.');
            }

            $totalGrams += $grams;
            $solids = self::micro($line['total_solids_percent'] ?? 0);
            $water = isset($line['water_percent']) ? self::micro($line['water_percent']) : 100_000_000 - $solids;

            foreach ([
                'pod' => $line['pod'] ?? 0,
                'pac' => $line['pac'] ?? 0,
                'milk_fat' => $line['milk_fat_percent'] ?? 0,
                'other_fat' => $line['other_fat_percent'] ?? 0,
                'slng' => $line['slng_percent'] ?? 0,
                'fiber' => $line['fiber_percent'] ?? 0,
            ] as $key => $value) {
                $sums[$key] += intdiv($grams * self::micro($value), 1_000_000);
            }

            $sums['solids'] += intdiv($grams * $solids, 1_000_000);
            $sums['water'] += intdiv($grams * $water, 1_000_000);
            $sums['cost'] += intdiv($grams * self::micro($line['cost_per_kg'] ?? 0), 1_000_000);
        }

        if ($totalGrams <= 0) {
            throw new InvalidArgumentException('A recipe needs at least one ingredient with a positive weight.');
        }

        $total = $totalGrams / 1_000_000;
        // Ingredient values are per 100 g: sum(g * value) / W is the weighted value per 100 g, and x10 is the index per 1,000 g.
        $percent = static fn (int $sum): float => $sum / 1_000_000 / $total;

        $podRaw = $sums['pod'] / 1_000_000 / $total * 10;
        $slngPercent = $percent($sums['slng']);
        $pacRaw = $sums['pac'] / 1_000_000 / $total * 10;
        $pac = $pacRaw + $slngPercent * 10 * self::MILK_SALTS_FACTOR;
        $milkFat = $percent($sums['milk_fat']);
        $otherFat = $percent($sums['other_fat']);
        $solidsPercent = min(100.0, $percent($sums['solids']));
        $waterPercent = max(0.0, min(100.0, $percent($sums['water'])));
        $fiberPercent = $percent($sums['fiber']);
        $slngMax = max(0.0, (100 - $solidsPercent) / self::LACTOSE_DIVISOR);

        $boundWater = min($waterPercent, ($slngPercent + $fiberPercent) * self::BOUND_WATER_PER_GRAM);
        $freezableWater = max(0.0, $waterPercent - $boundWater);
        $freezingPoint = $waterPercent > 0 ? -self::CRYOSCOPIC_PER_SUCROSE_GRAM_PER_KG_WATER * $pac * 1000 / ($waterPercent * 10) : 0.0;
        $curve = [];

        foreach (self::CURVE_TEMPERATURES as $temperature) {
            $iceFraction = $temperature < $freezingPoint ? 1 - $freezingPoint / $temperature : 0.0;
            $iceFraction = max(0.0, min(1.0, $iceFraction));
            $curve[] = [
                'temperature_celsius' => $temperature,
                'ice_fraction_of_freezable_water' => self::round($iceFraction, 4),
                'frozen_water_percent' => self::round($freezableWater * $iceFraction, 2),
                'unfrozen_water_percent' => self::round($waterPercent - $freezableWater * $iceFraction, 2),
            ];
        }

        $serving = $category->isFrozen()
            ? max(-20.0, min(-8.0, $freezingPoint / (1 - $category->targetIceFraction())))
            : 18.0;

        $overrun = max(0.0, (float) ($options['overrun_percent'] ?? 0));
        $density = (float) ($options['mix_density'] ?? self::DEFAULT_MIX_DENSITY_KG_PER_L);
        $costPerKg = $sums['cost'] / 1_000_000 / $total;
        $mixLitres = $total / 1000 / $density;
        $gelatoLitres = $mixLitres * (1 + $overrun / 100);
        $batchCost = $sums['cost'] / 1_000_000 / 1000;
        $portion = (float) ($options['serving_portion_grams'] ?? 80);
        $tub = (float) ($options['tub_weight_grams'] ?? 2500);
        // The finished weight per litre of gelato shrinks with the overrun: 1 L of gelato weighs density / (1 + overrun).
        $gelatoKgPerLitre = $density / (1 + $overrun / 100);

        $metrics = [
            'total_grams' => self::round($total, 3),
            'pod' => self::round($podRaw, 2),
            'pac' => self::round($pac, 2),
            'pac_milk_salts' => self::round($slngPercent * 10 * self::MILK_SALTS_FACTOR, 2),
            'milk_fat_percent' => self::round($milkFat, 2),
            'other_fat_percent' => self::round($otherFat, 2),
            'total_fat_percent' => self::round($milkFat + $otherFat, 2),
            'slng_percent' => self::round($slngPercent, 2),
            'slng_max_safe_threshold' => self::round($slngMax, 2),
            'other_solids_percent' => self::round(max(0.0, $solidsPercent - $milkFat - $otherFat - $slngPercent), 2),
            'total_solids_percent' => self::round($solidsPercent, 2),
            'water_percent' => self::round($waterPercent, 2),
            'bound_water_percent' => self::round($boundWater, 2),
            'freezable_water_percent' => self::round($freezableWater, 2),
            'freezing_point_celsius' => self::round($freezingPoint, 2),
            'ideal_serving_temp_celsius' => self::round($serving, 1),
            'freezing_curve' => $curve,
            'lactose_risk' => $this->lactoseRisk($slngPercent, $slngMax),
            'overrun_percent' => self::round($overrun, 2),
            'cost' => [
                'cost_per_kg' => self::round($costPerKg, 4),
                'cost_per_gram' => self::round($costPerKg / 1000, 6),
                'batch_cost' => self::round($batchCost, 2),
                'mix_litres' => self::round($mixLitres, 3),
                'cost_per_litre_mix' => self::round($batchCost / $mixLitres, 4),
                'gelato_litres' => self::round($gelatoLitres, 3),
                'cost_per_litre_gelato' => self::round($batchCost / $gelatoLitres, 4),
                'cost_per_tub' => self::round($costPerKg * $tub / 1000, 4),
                'cost_per_portion' => self::round($costPerKg * $portion / 1000, 4),
                'portions_per_litre_gelato' => $portion > 0 ? self::round($gelatoKgPerLitre * 1000 / $portion, 2) : null,
            ],
        ];
        $metrics['evaluation'] = $this->evaluate($metrics, $category, $options['bands'] ?? []);

        return $metrics;
    }

    /**
     * Overrun from the measured volumes (liters): (V_gelato - V_mix) / V_mix * 100.
     */
    public function overrun(float $gelatoLitres, float $mixLitres): float
    {
        if ($mixLitres <= 0) {
            throw new InvalidArgumentException('The liquid mix volume must be positive.');
        }

        return self::round(($gelatoLitres - $mixLitres) / $mixLitres * 100, 2);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, array{0: float|int, 1: float|int}>  $overrides
     * @return array{overall: string, indicators: array<string, array{value: float, min: float, max: float, status: string, diagnostic: string}>}
     */
    public function evaluate(array $metrics, RecipeCategory $category, array $overrides = []): array
    {
        $bands = $overrides + $category->bands();
        $values = [
            'pod' => $metrics['pod'],
            'pac' => $metrics['pac'],
            'milk_fat' => $metrics['milk_fat_percent'],
            'total_fat' => $metrics['total_fat_percent'],
            'slng' => $metrics['slng_percent'],
            'total_solids' => $metrics['total_solids_percent'],
            'serving_temp' => $metrics['ideal_serving_temp_celsius'],
        ];
        $indicators = [];
        $worst = 'OPTIMAL';

        foreach ($values as $key => $value) {
            [$min, $max] = [(float) $bands[$key][0], (float) $bands[$key][1]];
            $tolerance = max(0.5, ($max - $min) * 0.15);
            $status = match (true) {
                $value >= $min && $value <= $max => 'OPTIMAL',
                $value >= $min - $tolerance && $value <= $max + $tolerance => 'WARNING',
                default => 'OUT_OF_RANGE',
            };
            $direction = $value < $min ? 'LOW' : ($value > $max ? 'HIGH' : 'OK');

            if ($key === 'slng' && $metrics['lactose_risk']['status'] !== 'OK') {
                $status = $metrics['lactose_risk']['status'] === 'EXCEEDED' ? 'OUT_OF_RANGE' : ($status === 'OUT_OF_RANGE' ? $status : 'WARNING');
                $direction = 'LACTOSE';
            }

            $indicators[$key] = ['value' => (float) $value, 'min' => $min, 'max' => $max, 'status' => $status, 'diagnostic' => strtoupper($key).'_'.$direction];
            $worst = self::worse($worst, $status);
        }

        return ['overall' => $worst, 'indicators' => $indicators];
    }

    /**
     * @return array{status: string, slng_percent: float, slng_max: float, margin: float}
     */
    private function lactoseRisk(float $slng, float $slngMax): array
    {
        $status = match (true) {
            $slng > $slngMax => 'EXCEEDED',
            $slng >= $slngMax * 0.95 => 'APPROACHING',
            default => 'OK',
        };

        return ['status' => $status, 'slng_percent' => self::round($slng, 2), 'slng_max' => self::round($slngMax, 2), 'margin' => self::round($slngMax - $slng, 2)];
    }

    private static function worse(string $a, string $b): string
    {
        $rank = ['OPTIMAL' => 0, 'WARNING' => 1, 'OUT_OF_RANGE' => 2];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    public static function micro(float|int|string|null $value): int
    {
        return (int) round((float) $value * 1_000_000);
    }

    public static function round(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;

        return ($value < 0 ? -1 : 1) * floor(abs($value) * $factor + 0.5 + 1e-9) / $factor;
    }
}
