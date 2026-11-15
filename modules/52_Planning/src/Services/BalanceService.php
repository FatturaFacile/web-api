<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningScenario;

/**
 * Demand-supply balancing of a scenario. The gap of an item in a month is supply minus demand: negative is a shortage,
 * positive a surplus. With carry-over a surplus or a shortage rolls into the next month (a running balance); without it
 * every month stands alone.
 */
final class BalanceService
{
    /**
     * @return array{scenario_id: string, carry_over: bool, periods: list<string>, items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function balance(PlanningScenario $scenario, bool $carryOver = true): array
    {
        $periods = PlanningPeriods::months($scenario);
        $lines = PlanningPlanLine::query()->where('scenario_id', $scenario->id)->orderBy('item_code')->orderBy('period')->get();

        $perItem = [];
        foreach ($lines as $line) {
            $code = $line->item_code;
            $month = $line->period->toDateString();
            $perItem[$code]['item_name'] = $line->item_name ?? ($perItem[$code]['item_name'] ?? null);
            $perItem[$code]['periods'][$month][$line->line_type] = bcadd($perItem[$code]['periods'][$month][$line->line_type] ?? '0', (string) $line->quantity, 3);
        }

        ksort($perItem);
        $items = [];
        $totals = ['total_demand' => '0.000', 'total_supply' => '0.000', 'total_gap' => '0.000', 'shortage_items' => 0, 'surplus_items' => 0, 'max_shortage' => '0.000'];

        foreach ($perItem as $code => $data) {
            $item = $this->itemRow((string) $code, $data['item_name'], $data['periods'], $periods, $carryOver);
            $items[] = $item;
            $totals['total_demand'] = bcadd($totals['total_demand'], $item['total_demand'], 3);
            $totals['total_supply'] = bcadd($totals['total_supply'], $item['total_supply'], 3);
            $totals['total_gap'] = bcadd($totals['total_gap'], $item['total_gap'], 3);
            $totals['max_shortage'] = bcadd($totals['max_shortage'], $item['max_shortage'], 3);
            $totals['shortage_items'] += bccomp($item['max_shortage'], '0', 3) > 0 ? 1 : 0;
            $totals['surplus_items'] += bccomp($item['ending_gap'], '0', 3) > 0 ? 1 : 0;
        }

        return ['scenario_id' => $scenario->id, 'carry_over' => $carryOver, 'periods' => $periods, 'items' => $items, 'totals' => $totals];
    }

    /**
     * @param  array<string, array<string, string>>  $quantities
     * @param  list<string>  $periods
     * @return array<string, mixed>
     */
    private function itemRow(string $code, ?string $name, array $quantities, array $periods, bool $carryOver): array
    {
        $rows = [];
        $running = '0';
        $totalDemand = '0';
        $totalSupply = '0';
        $maxShortage = '0';
        $shortagePeriods = 0;

        foreach ($periods as $period) {
            $demand = $quantities[$period][PlanningPlanLine::Demand] ?? '0';
            $supply = $quantities[$period][PlanningPlanLine::Supply] ?? '0';
            $gap = bcsub($supply, $demand, 3);
            $running = $carryOver ? bcadd($running, $gap, 3) : $gap;
            $shortage = bccomp($running, '0', 3) < 0 ? bcmul($running, '-1', 3) : '0.000';

            $totalDemand = bcadd($totalDemand, $demand, 3);
            $totalSupply = bcadd($totalSupply, $supply, 3);
            $maxShortage = bccomp($shortage, $maxShortage, 3) > 0 ? $shortage : $maxShortage;
            $shortagePeriods += bccomp($shortage, '0', 3) > 0 ? 1 : 0;

            $rows[] = [
                'period' => $period,
                'demand' => bcadd($demand, '0', 3),
                'supply' => bcadd($supply, '0', 3),
                'gap' => $gap,
                'balance' => $running,
                'status' => match (bccomp($running, '0', 3)) {
                    -1 => 'shortage',
                    1 => 'surplus',
                    default => 'balanced',
                },
            ];
        }

        return [
            'item_code' => $code,
            'item_name' => $name,
            'total_demand' => bcadd($totalDemand, '0', 3),
            'total_supply' => bcadd($totalSupply, '0', 3),
            'total_gap' => bcsub($totalSupply, $totalDemand, 3),
            'ending_gap' => $running,
            'max_shortage' => bcadd($maxShortage, '0', 3),
            'shortage_periods' => $shortagePeriods,
            'periods' => $rows,
        ];
    }
}
