<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;

/**
 * Constrained least-squares auto balance: finds the grams of the chosen ingredients that hit the target batch weight, POD, PAC,
 * total fat %, SLNG % and total solids % as closely as the bounds allow.
 *
 * Every target is a linear equation in the weights w_i once the batch weight W is fixed, so the problem is
 * min sum_k rho_k * ((a_k . w - t_k) / s_k)^2 with lo_i <= w_i <= hi_i (non-negative by default). It is convex, and cyclic exact
 * coordinate descent with clamping converges to the global optimum deterministically. When the lactose guardrail
 * (SLNG <= (100 - total solids) / 6.9) is broken by the result, a SLNG ceiling equation at 97 % of the limit is added and the
 * system is solved again. If a target cannot be met the closest feasible mix is returned with the residual of every target.
 */
final class AutoBalanceSolver
{
    private const int MAX_SWEEPS = 6000;

    private const float TOLERANCE_GRAMS = 1e-7;

    public function __construct(private readonly RecipeBalancingEngine $engine) {}

    /**
     * @param  list<array{id: string, pod?: float|int|string, pac?: float|int|string, milk_fat_percent?: float|int|string, other_fat_percent?: float|int|string, slng_percent?: float|int|string, total_solids_percent?: float|int|string, water_percent?: float|int|string|null, fiber_percent?: float|int|string, cost_per_kg?: float|int|string, min_grams?: float|int|null, max_grams?: float|int|null}>  $ingredients
     * @param  array{weight: float|int, pod?: float|int|null, pac?: float|int|null, total_fat?: float|int|null, slng?: float|int|null, total_solids?: float|int|null}  $targets
     * @return array{status: string, weights: array<string, float>, achieved: array<string, mixed>, residuals: array<string, array{target: float, achieved: float, residual: float, met: bool}>, slng_guard_applied: bool}
     */
    public function solve(array $ingredients, array $targets, RecipeCategory $category): array
    {
        $weight = (float) $targets['weight'];

        if ($weight <= 0) {
            throw new InvalidArgumentException('The target batch weight must be positive.');
        }

        if (count($ingredients) === 0) {
            throw new InvalidArgumentException('Choose at least one ingredient to balance.');
        }

        $rows = $this->rows($ingredients, $targets, $weight);
        $solution = $this->descend($ingredients, $rows, $weight);
        $guardApplied = false;

        for ($pass = 0; $pass < 3; $pass++) {
            $metrics = $this->engine->analyze($this->linesFrom($ingredients, $solution), $category);

            if ($metrics['slng_percent'] <= $metrics['slng_max_safe_threshold']) {
                break;
            }

            $guardApplied = true;
            $rows['slng_ceiling'] = ['coefficients' => array_map(static fn (array $i): float => (float) ($i['slng_percent'] ?? 0), $ingredients), 'target' => $metrics['slng_max_safe_threshold'] * 0.97 * $weight, 'scale' => $weight, 'rho' => 3.0];
            $solution = $this->descend($ingredients, $rows, $weight);
        }

        $weights = [];

        foreach ($ingredients as $index => $ingredient) {
            $weights[$ingredient['id']] = RecipeBalancingEngine::round($solution[$index], 2);
        }

        $achieved = $this->engine->analyze($this->linesFrom($ingredients, array_values($weights)), $category);
        $residuals = $this->residuals($targets, $achieved);
        $allMet = ! in_array(false, array_column($residuals, 'met'), true);

        return [
            'status' => $allMet ? 'EXACT' : 'CLOSEST_FEASIBLE',
            'weights' => $weights,
            'achieved' => $achieved,
            'residuals' => $residuals,
            'slng_guard_applied' => $guardApplied,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  array<string, mixed>  $targets
     * @return array<string, array{coefficients: list<float>, target: float, scale: float, rho: float}>
     */
    private function rows(array $ingredients, array $targets, float $weight): array
    {
        $column = static fn (callable $value): array => array_map($value, $ingredients);
        $rows = [
            'weight' => ['coefficients' => $column(static fn (): float => 1.0), 'target' => $weight, 'scale' => $weight / 100, 'rho' => 4.0],
        ];

        if (isset($targets['pod'])) {
            $rows['pod'] = ['coefficients' => $column(static fn (array $i): float => (float) ($i['pod'] ?? 0)), 'target' => (float) $targets['pod'] * $weight / 10, 'scale' => $weight / 10, 'rho' => 1.0];
        }

        if (isset($targets['pac'])) {
            $rows['pac'] = ['coefficients' => $column(static fn (array $i): float => (float) ($i['pac'] ?? 0) + RecipeBalancingEngine::MILK_SALTS_FACTOR * (float) ($i['slng_percent'] ?? 0)), 'target' => (float) $targets['pac'] * $weight / 10, 'scale' => $weight / 10, 'rho' => 1.0];
        }

        foreach (['total_fat' => fn (array $i): float => (float) ($i['milk_fat_percent'] ?? 0) + (float) ($i['other_fat_percent'] ?? 0), 'slng' => fn (array $i): float => (float) ($i['slng_percent'] ?? 0), 'total_solids' => fn (array $i): float => (float) ($i['total_solids_percent'] ?? 0)] as $key => $value) {
            if (isset($targets[$key])) {
                $rows[$key] = ['coefficients' => $column($value), 'target' => (float) $targets[$key] * $weight, 'scale' => $weight, 'rho' => 1.0];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  array<string, array{coefficients: list<float>, target: float, scale: float, rho: float}>  $rows
     * @return list<float>
     */
    private function descend(array $ingredients, array $rows, float $weight): array
    {
        $count = count($ingredients);
        $lower = [];
        $upper = [];

        foreach ($ingredients as $ingredient) {
            $lower[] = max(0.0, (float) ($ingredient['min_grams'] ?? 0));
            $upper[] = isset($ingredient['max_grams']) ? max(0.0, (float) $ingredient['max_grams']) : $weight;
        }

        $w = array_map(static fn (int $i): float => min($upper[$i], max($lower[$i], $weight / $count)), range(0, $count - 1));
        $residual = [];

        foreach ($rows as $key => $row) {
            $residual[$key] = -$row['target'];

            foreach ($row['coefficients'] as $i => $coefficient) {
                $residual[$key] += $coefficient * $w[$i];
            }
        }

        for ($sweep = 0; $sweep < self::MAX_SWEEPS; $sweep++) {
            $largestStep = 0.0;

            for ($j = 0; $j < $count; $j++) {
                $gradient = 0.0;
                $curvature = 0.0;

                foreach ($rows as $key => $row) {
                    $a = $row['coefficients'][$j];
                    $factor = $row['rho'] / ($row['scale'] * $row['scale']);
                    $gradient += $factor * $residual[$key] * $a;
                    $curvature += $factor * $a * $a;
                }

                if ($curvature <= 0.0) {
                    continue;
                }

                $next = min($upper[$j], max($lower[$j], $w[$j] - $gradient / $curvature));
                $step = $next - $w[$j];

                if ($step !== 0.0) {
                    foreach ($rows as $key => $row) {
                        $residual[$key] += $row['coefficients'][$j] * $step;
                    }

                    $w[$j] = $next;
                    $largestStep = max($largestStep, abs($step));
                }
            }

            if ($largestStep < self::TOLERANCE_GRAMS) {
                break;
            }
        }

        return $w;
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  list<float>  $weights
     * @return list<array<string, mixed>>
     */
    private function linesFrom(array $ingredients, array $weights): array
    {
        $lines = [];

        foreach ($ingredients as $index => $ingredient) {
            $lines[] = ['grams' => max(0.0, $weights[$index])] + $ingredient;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $achieved
     * @return array<string, array{target: float, achieved: float, residual: float, met: bool}>
     */
    private function residuals(array $targets, array $achieved): array
    {
        $map = ['weight' => ['total_grams', 0.5], 'pod' => ['pod', 1.0], 'pac' => ['pac', 1.0], 'total_fat' => ['total_fat_percent', 0.1], 'slng' => ['slng_percent', 0.1], 'total_solids' => ['total_solids_percent', 0.1]];
        $result = [];

        foreach ($map as $key => [$metric, $tolerance]) {
            if (! isset($targets[$key])) {
                continue;
            }

            $target = (float) $targets[$key];
            $value = (float) $achieved[$metric];
            $result[$key] = ['target' => $target, 'achieved' => $value, 'residual' => RecipeBalancingEngine::round($value - $target, 3), 'met' => abs($value - $target) <= $tolerance];
        }

        return $result;
    }
}
