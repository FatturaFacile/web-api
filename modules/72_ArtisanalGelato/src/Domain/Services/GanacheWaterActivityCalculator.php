<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use InvalidArgumentException;

/**
 * Water activity (a_w) and shelf life of a ganache or filling with the Norrish equation for a mixture of solutes:
 *
 *   a_w = X_w * exp(-(sum_i sqrt(K_i) * X_i)^2)
 *
 * X are mole fractions of water and of every dissolved sugar or polyol, K_i the Norrish constant of the solute (sucrose 6.47,
 * glucose 2.25, invert sugar 2.20, sorbitol 0.45). The shelf life is the microbial/mold risk band of the a_w, never beyond the
 * stated cap: a_w < 0.70 is shelf stable, < 0.80 stops most bacteria and moulds, > 0.90 supports growth within days.
 */
final class GanacheWaterActivityCalculator
{
    /** @var array<string, array{molar_mass: float, k: float}> */
    private const array SOLUTES = [
        'sucrose' => ['molar_mass' => 342.30, 'k' => 6.47],
        'glucose' => ['molar_mass' => 180.16, 'k' => 2.25],
        'invert' => ['molar_mass' => 180.16, 'k' => 2.20],
        'sorbitol' => ['molar_mass' => 182.17, 'k' => 0.45],
    ];

    private const float WATER_MOLAR_MASS = 18.015;

    /**
     * @param  list<array{grams: float|int|string, water_percent?: float|int|string|null, sugar_percent?: float|int|string, solute_class?: string}>  $lines
     * @return array{water_percent: float, water_activity: float, shelf_life_days: int, risk_band: string, solute_grams: array<string, float>}
     */
    public function calculate(array $lines): array
    {
        $total = 0.0;
        $water = 0.0;
        $solutes = array_fill_keys(array_keys(self::SOLUTES), 0.0);

        foreach ($lines as $line) {
            $grams = (float) $line['grams'];

            if ($grams < 0) {
                throw new InvalidArgumentException('Ingredient weights cannot be negative.');
            }

            $total += $grams;
            $water += $grams * (float) ($line['water_percent'] ?? 0) / 100;
            $class = (string) ($line['solute_class'] ?? 'none');

            if (isset($solutes[$class])) {
                $solutes[$class] += $grams * (float) ($line['sugar_percent'] ?? 0) / 100;
            }
        }

        if ($total <= 0) {
            throw new InvalidArgumentException('A ganache needs at least one ingredient with a positive weight.');
        }

        $waterMoles = $water / self::WATER_MOLAR_MASS;
        $moles = [];

        foreach ($solutes as $class => $grams) {
            $moles[$class] = $grams / self::SOLUTES[$class]['molar_mass'];
        }

        $soluteMoles = array_sum($moles);
        $waterActivity = 1.0;

        if ($waterMoles + $soluteMoles > 0 && $waterMoles > 0) {
            $denominator = $waterMoles + $soluteMoles;
            $weighted = 0.0;

            foreach ($moles as $class => $mol) {
                $weighted += sqrt(self::SOLUTES[$class]['k']) * $mol / $denominator;
            }

            $waterActivity = $waterMoles / $denominator * exp(-($weighted ** 2));
        } elseif ($waterMoles <= 0) {
            $waterActivity = 0.0;
        }

        $waterActivity = RecipeBalancingEngine::round($waterActivity, 3);
        [$days, $band] = $this->shelfLife($waterActivity);

        return [
            'water_percent' => RecipeBalancingEngine::round($water / $total * 100, 2),
            'water_activity' => $waterActivity,
            'shelf_life_days' => $days,
            'risk_band' => $band,
            'solute_grams' => array_map(static fn (float $grams): float => RecipeBalancingEngine::round($grams, 2), $solutes),
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function shelfLife(float $waterActivity): array
    {
        return match (true) {
            $waterActivity < 0.70 => [365, 'stable'],
            $waterActivity < 0.80 => [180, 'low'],
            $waterActivity < 0.85 => [60, 'moderate'],
            $waterActivity < 0.90 => [21, 'elevated'],
            $waterActivity < 0.95 => [7, 'high'],
            default => [3, 'critical'],
        };
    }
}
