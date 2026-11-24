<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Illuminate\Support\Facades\DB;
use Modules\Controlling\Models\AnalyticalCostCenter;
use Modules\Controlling\Models\AnalyticalJob;
use Modules\Controlling\Support\Decimal;

/**
 * Cost center tree (direct revenue, direct costs, incoming and outgoing overhead allocations, net center result, rolled up
 * to the parents) and the profit and loss of a job.
 */
final class CostCenterReportService
{
    private const array CostNatures = ['direct_material', 'direct_labor', 'variable_overhead', 'fixed_overhead', 'opex'];

    /**
     * @return list<array<string, mixed>> roots with their children
     */
    public function tree(string $tenantId, int $year, int $fromMonth = 1, int $toMonth = 12): array
    {
        $own = [];
        $rows = DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('cost_center_id')
            ->where('fiscal_year', $year)->whereBetween('fiscal_month', [$fromMonth, $toMonth])
            ->selectRaw("cost_center_id,
                SUM(CASE WHEN source_type <> 'overhead_allocation' AND nature_type = 'revenue' THEN amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN source_type <> 'overhead_allocation' AND nature_type <> 'revenue' THEN amount ELSE 0 END) AS direct_costs,
                SUM(CASE WHEN source_type = 'overhead_allocation' AND amount > 0 THEN amount ELSE 0 END) AS allocations_in,
                SUM(CASE WHEN source_type = 'overhead_allocation' AND amount < 0 THEN -amount ELSE 0 END) AS allocations_out")
            ->groupBy('cost_center_id')->get();
        foreach ($rows as $row) {
            $own[(string) $row->cost_center_id] = array_map(static fn ($v): string => Decimal::norm(number_format((float) $v, 4, '.', '')), ['revenue' => $row->revenue, 'direct_costs' => $row->direct_costs, 'allocations_in' => $row->allocations_in, 'allocations_out' => $row->allocations_out]);
        }

        $centers = AnalyticalCostCenter::query()->where('tenant_id', $tenantId)->orderBy('code')->get();
        $children = [];
        foreach ($centers as $center) {
            $children[(string) ($center->parent_id ?? '')][] = $center;
        }
        $build = function (AnalyticalCostCenter $center) use (&$build, $children, $own): array {
            $figures = $own[(string) $center->id] ?? ['revenue' => '0.0000', 'direct_costs' => '0.0000', 'allocations_in' => '0.0000', 'allocations_out' => '0.0000'];
            $nodes = array_map($build, $children[(string) $center->id] ?? []);
            $rolled = $figures;
            foreach ($nodes as $node) {
                foreach (array_keys($rolled) as $key) {
                    $rolled[$key] = Decimal::add($rolled[$key], $node['rollup'][$key]);
                }
            }

            return [
                'id' => $center->id, 'code' => $center->code, 'name' => $center->name, 'center_type' => $center->center_type, 'parent_id' => $center->parent_id,
                'own' => $figures + ['net_result' => $this->net($figures)], 'rollup' => $rolled + ['net_result' => $this->net($rolled)], 'children' => $nodes,
            ];
        };

        return array_map($build, $children[''] ?? []);
    }

    /**
     * @param  array<string, string>  $f
     */
    private function net(array $f): string
    {
        return Decimal::add(Decimal::sub(Decimal::sub($f['revenue'], $f['direct_costs']), $f['allocations_in']), $f['allocations_out']);
    }

    /**
     * Profit and loss of a job against its budget.
     *
     * @return array<string, mixed>
     */
    public function jobPnl(AnalyticalJob $job): array
    {
        $by = DB::table('analytical_ledger_entries')->where('tenant_id', $job->tenant_id)->whereNull('deleted_at')->where('job_id', $job->id)
            ->selectRaw('nature_type, source_type, SUM(amount) AS amount, SUM(quantity) AS quantity')->groupBy('nature_type', 'source_type')->get();
        $sum = ['revenue' => '0.0000', 'direct_material' => '0.0000', 'direct_labor' => '0.0000', 'other_direct' => '0.0000', 'allocated_overheads' => '0.0000', 'labor_hours' => '0.0000'];
        foreach ($by as $row) {
            $amount = Decimal::norm(number_format((float) $row->amount, 4, '.', ''));
            if ($row->source_type === 'overhead_allocation') {
                $sum['allocated_overheads'] = Decimal::add($sum['allocated_overheads'], $amount);
            } elseif (in_array($row->nature_type, ['revenue', 'direct_material', 'direct_labor'], true)) {
                $sum[$row->nature_type] = Decimal::add($sum[$row->nature_type], $amount);
                if ($row->nature_type === 'direct_labor') {
                    $sum['labor_hours'] = Decimal::add($sum['labor_hours'], number_format((float) $row->quantity, 4, '.', ''));
                }
            } elseif (in_array($row->nature_type, self::CostNatures, true)) {
                $sum['other_direct'] = Decimal::add($sum['other_direct'], $amount);
            }
        }
        $directCost = Decimal::add(Decimal::add($sum['direct_material'], $sum['direct_labor']), $sum['other_direct']);
        $totalCost = Decimal::add($directCost, $sum['allocated_overheads']);
        $budget = Decimal::norm($job->estimated_cost_budget);
        $contract = Decimal::norm($job->contract_value);
        $consumed = Decimal::percent($totalCost, $budget);
        $eacCost = Decimal::cmp($totalCost, $budget) > 0 ? $totalCost : $budget;

        return [
            'job_id' => $job->id, 'code' => $job->code, 'name' => $job->name, 'status' => $job->status,
            'contract_value' => $contract, 'invoiced_to_date' => $sum['revenue'], 'open_to_invoice' => Decimal::sub($contract, $sum['revenue']),
            'direct_material' => $sum['direct_material'], 'direct_labor' => $sum['direct_labor'], 'other_direct_costs' => $sum['other_direct'], 'allocated_overheads' => $sum['allocated_overheads'],
            'actual_cost' => $totalCost, 'estimated_cost_budget' => $budget, 'cost_consumed_percent' => $consumed, 'open_commitments' => Decimal::cmp($budget, $totalCost) > 0 ? Decimal::sub($budget, $totalCost) : '0.0000',
            'labor_hours' => $sum['labor_hours'],
            'actual_margin' => Decimal::sub($sum['revenue'], $totalCost), 'estimated_margin' => Decimal::sub($contract, $budget),
            'projected_final_margin' => Decimal::sub($contract, $eacCost), 'completion_percent' => Decimal::percent(Decimal::cmp($totalCost, $budget) > 0 ? $budget : $totalCost, $budget),
        ];
    }
}
