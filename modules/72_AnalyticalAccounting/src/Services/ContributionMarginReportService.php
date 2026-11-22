<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AnalyticalAccounting\Domain\Enums\EntryType;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Multi-level contribution margin income statement of the management ledger: operating revenues, direct variable costs,
 * contribution margin I, direct fixed costs, contribution margin II, indirect and apportioned costs and the operating
 * result. Filterable by period and by every dimension, with an optional hierarchy roll-up of the chosen center.
 */
final class ContributionMarginReportService
{
    private const array GroupColumns = ['cost_center' => 'cost_center_id', 'profit_center' => 'profit_center_id', 'project' => 'project_id', 'product_line' => 'product_line_id'];

    public function __construct(private readonly CenterService $centers) {}

    /**
     * @param  array<string, mixed>  $filters  from, to, cost_center_id, profit_center_id, project_id, product_line_id, include_descendants, group_by
     * @return array<string, mixed>
     */
    public function statement(string $tenantId, array $filters): array
    {
        $total = $this->figures($this->base($tenantId, $filters));
        $groupBy = $filters['group_by'] ?? null;
        $breakdown = [];
        if ($groupBy !== null) {
            $column = self::GroupColumns[$groupBy] ?? throw new InvalidArgumentException('Unknown group_by dimension.');
            $keys = $this->base($tenantId, $filters)->whereNotNull("l.{$column}")->distinct()->pluck("l.{$column}");
            foreach ($keys as $key) {
                $breakdown[] = ['dimension' => $groupBy, 'id' => (string) $key] + $this->figures($this->base($tenantId, $filters)->where("l.{$column}", $key));
            }
        }

        return ['filters' => array_intersect_key($filters, array_flip(['from', 'to', 'cost_center_id', 'profit_center_id', 'project_id', 'product_line_id', 'include_descendants', 'group_by'])), 'statement' => $total, 'breakdown' => $breakdown];
    }

    /**
     * CSV lines of the statement, in the order of the income statement.
     *
     * @param  array<string, mixed>  $statement  the "statement" part of statement()
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function toRows(array $statement): array
    {
        return [
            ['operating_revenues', $statement['operating_revenues'], '100.00'],
            ['direct_variable_costs', $statement['direct_variable_costs'], ''],
            ['contribution_margin_1', $statement['contribution_margin_1'], $statement['contribution_margin_1_pct']],
            ['direct_fixed_costs', $statement['direct_fixed_costs'], ''],
            ['contribution_margin_2', $statement['contribution_margin_2'], $statement['contribution_margin_2_pct']],
            ['indirect_costs', $statement['indirect_costs'], ''],
            ['apportioned_costs', $statement['apportioned_costs'], ''],
            ['operating_result', $statement['operating_result'], $statement['operating_result_pct']],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function base(string $tenantId, array $filters): Builder
    {
        $query = DB::table('analytical_entry_lines as l')
            ->join('analytical_entries as e', 'e.id', '=', 'l.analytical_entry_id')
            ->where('l.tenant_id', $tenantId)->whereNull('l.deleted_at')->whereNull('e.deleted_at')
            ->where('e.is_reversed', false)->where('e.entry_type', '!=', EntryType::REVERSAL->value);
        if (! empty($filters['from'])) {
            $query->where('l.posting_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('l.posting_date', '<=', $filters['to']);
        }
        $descendants = ! empty($filters['include_descendants']);
        foreach (['cost_center_id', 'profit_center_id'] as $column) {
            if (! empty($filters[$column])) {
                $ids = $descendants ? $this->centers->subtreeIds($tenantId, (string) $filters[$column]) : [(string) $filters[$column]];
                $query->whereIn("l.{$column}", $ids);
            }
        }
        foreach (['project_id', 'product_line_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("l.{$column}", $filters[$column]);
            }
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function figures(Builder $query): array
    {
        $rows = (clone $query)->selectRaw('l.cost_element_type as element, e.entry_type as entry_type, SUM(l.amount) as total')->groupBy('l.cost_element_type', 'e.entry_type')->get();
        $sum = ['REVENUE' => '0', 'VARIABLE_DIRECT' => '0', 'FIXED_DIRECT' => '0', 'INDIRECT' => '0', 'APPORTIONED' => '0', 'NON_OPERATING' => '0'];
        foreach ($rows as $row) {
            $key = match (true) {
                $row->element === 'INDIRECT_AUXILIARY' && $row->entry_type === EntryType::STEP_DOWN_APPORTIONMENT->value => 'APPORTIONED',
                $row->element === 'INDIRECT_AUXILIARY' => 'INDIRECT',
                default => (string) $row->element,
            };
            // SQLite returns SUM() as a float: round to the 4 decimals of the ledger instead of truncating the noise.
            $sum[$key] = bcadd($sum[$key], number_format((float) $row->total, 4, '.', ''), 4);
        }
        $revenue = $sum['REVENUE'];
        $cm1 = bcsub($revenue, $sum['VARIABLE_DIRECT'], 4);
        $cm2 = bcsub($cm1, $sum['FIXED_DIRECT'], 4);
        $result = bcsub(bcsub($cm2, $sum['INDIRECT'], 4), $sum['APPORTIONED'], 4);

        return [
            'operating_revenues' => bcadd($revenue, '0', 2),
            'direct_variable_costs' => bcadd($sum['VARIABLE_DIRECT'], '0', 2),
            'contribution_margin_1' => bcadd($cm1, '0', 2),
            'contribution_margin_1_pct' => Money::percent($cm1, $revenue),
            'direct_fixed_costs' => bcadd($sum['FIXED_DIRECT'], '0', 2),
            'contribution_margin_2' => bcadd($cm2, '0', 2),
            'contribution_margin_2_pct' => Money::percent($cm2, $revenue),
            'indirect_costs' => bcadd($sum['INDIRECT'], '0', 2),
            'apportioned_costs' => bcadd($sum['APPORTIONED'], '0', 2),
            'operating_result' => bcadd($result, '0', 2),
            'operating_result_pct' => Money::percent($result, $revenue),
            'non_operating' => bcadd($sum['NON_OPERATING'], '0', 2),
        ];
    }
}
