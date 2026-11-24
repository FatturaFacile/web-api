<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetDriver;

/**
 * Allocation of an amount over a range of periods: evenly, by the seasonal weights of the version or by explicit
 * percentages. The result always adds up to the amount to the cent: every period is rounded and the last one takes the
 * rounding remainder.
 */
final class SpreadService
{
    /**
     * @param  list<numeric-string|int|float>|null  $seasonalWeights  the twelve weights of the version (period 1 first)
     * @param  list<numeric-string|int|float>|null  $percentages  one percentage per period of the range, adding up to 100
     * @return array<int, string> amount per period
     */
    public function spread(string $total, int $from, int $to, string $method, ?array $seasonalWeights = null, ?array $percentages = null): array
    {
        if ($from < 1 || $to > BudgetPeriods::Count || $from > $to) {
            throw ValidationException::withMessages(['end_period' => [__('budgeting_errors.spread.range')]]);
        }

        $periods = range($from, $to);
        $weights = match ($method) {
            BudgetDriver::Even => array_map(static fn (int $period): string => '1', $periods),
            BudgetDriver::Seasonal => $this->seasonal($periods, $seasonalWeights),
            BudgetDriver::Percentage => $this->percentages($periods, $percentages),
            default => throw ValidationException::withMessages(['spread_method' => [__('budgeting_errors.spread.method')]]),
        };

        $weightSum = array_reduce($weights, static fn (string $carry, string $weight): string => bcadd($carry, $weight, 6), '0');
        $result = [];
        $allocated = '0';

        foreach ($periods as $index => $period) {
            $isLast = $index === count($periods) - 1;
            $amount = $isLast ? bcsub($total, $allocated, 2) : bcround(bcdiv(bcmul($total, $weights[$index], 8), $weightSum, 8), 2);
            $allocated = bcadd($allocated, $amount, 2);
            $result[$period] = $amount;
        }

        return $result;
    }

    /**
     * @param  array<mixed>|null  $weights
     * @return list<string>
     */
    public function assertSeasonalWeights(?array $weights): array
    {
        if ($weights === null || count($weights) !== BudgetPeriods::Count) {
            throw ValidationException::withMessages(['seasonal_weights' => [__('budgeting_errors.spread.weights_count')]]);
        }

        $clean = array_map(static fn (mixed $weight): string => is_numeric($weight) ? bcadd((string) $weight, '0', 6) : '-1', array_values($weights));

        if (array_filter($clean, static fn (string $weight): bool => bccomp($weight, '0', 6) < 0) !== [] || bccomp(array_reduce($clean, static fn (string $carry, string $weight): string => bcadd($carry, $weight, 6), '0'), '0', 6) <= 0) {
            throw ValidationException::withMessages(['seasonal_weights' => [__('budgeting_errors.spread.weights_invalid')]]);
        }

        return $clean;
    }

    /**
     * @param  list<int>  $periods
     * @param  array<mixed>|null  $weights
     * @return list<string>
     */
    private function seasonal(array $periods, ?array $weights): array
    {
        if ($weights === null) {
            throw ValidationException::withMessages(['spread_method' => [__('budgeting_errors.spread.no_seasonal_weights')]]);
        }

        $clean = $this->assertSeasonalWeights($weights);
        $selected = array_map(static fn (int $period): string => $clean[$period - 1], $periods);

        if (bccomp(array_reduce($selected, static fn (string $carry, string $weight): string => bcadd($carry, $weight, 6), '0'), '0', 6) <= 0) {
            throw ValidationException::withMessages(['spread_method' => [__('budgeting_errors.spread.weights_invalid')]]);
        }

        return $selected;
    }

    /**
     * @param  list<int>  $periods
     * @param  array<mixed>|null  $percentages
     * @return list<string>
     */
    private function percentages(array $periods, ?array $percentages): array
    {
        if ($percentages === null || count($percentages) !== count($periods)) {
            throw ValidationException::withMessages(['percentages' => [__('budgeting_errors.spread.percentages_count', ['count' => count($periods)])]]);
        }

        $clean = array_map(static fn (mixed $value): string => is_numeric($value) ? bcadd((string) $value, '0', 4) : '-1', array_values($percentages));
        $sum = array_reduce($clean, static fn (string $carry, string $value): string => bcadd($carry, $value, 4), '0');

        if (array_filter($clean, static fn (string $value): bool => bccomp($value, '0', 4) < 0) !== [] || bccomp(bcsub($sum, '100', 4), '0.01', 4) > 0 || bccomp(bcsub('100', $sum, 4), '0.01', 4) > 0) {
            throw ValidationException::withMessages(['percentages' => [__('budgeting_errors.spread.percentages_sum')]]);
        }

        return $clean;
    }
}
