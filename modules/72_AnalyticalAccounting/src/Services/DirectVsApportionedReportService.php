<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\AnalyticalAccounting\Domain\Enums\EntryType;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Per center comparison of the direct actual cost with what the step-down cycles moved in and out: direct, inflows,
 * outflows, net analytical cost, variance against the direct cost and the share of the total net cost. With
 * include_descendants every row also carries the figures rolled up over its subtree.
 */
final class DirectVsApportionedReportService
{
    private const array CostElements = ['VARIABLE_DIRECT', 'FIXED_DIRECT', 'INDIRECT_AUXILIARY'];

    public function __construct(private readonly CenterService $centers) {}

    /**
     * @param  array<string, mixed>  $filters  from, to, center_id, include_descendants
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function compare(string $tenantId, array $filters): array
    {
        $per = $this->perCenter($tenantId, $filters);
        $flat = $this->centers->flat($tenantId);
        $descendants = ! empty($filters['include_descendants']);
        $scope = null;
        if (! empty($filters['center_id'])) {
            $scope = $descendants ? $this->centers->subtreeIds($tenantId, (string) $filters['center_id']) : [(string) $filters['center_id']];
        }

        $children = [];
        foreach ($flat as $row) {
            $children[(string) ($row['parent_id'] ?? '')][] = (string) $row['id'];
        }
        $zero = ['direct' => '0', 'inflow' => '0', 'outflow' => '0'];
        $rollup = function (string $id) use (&$rollup, $per, $children, $zero): array {
            $sum = $per[$id] ?? $zero;
            foreach ($children[$id] ?? [] as $child) {
                $c = $rollup($child);
                foreach ($sum as $k => $v) {
                    $sum[$k] = bcadd($v, $c[$k], 4);
                }
            }

            return $sum;
        };

        $rows = [];
        $totals = $zero;
        foreach ($flat as $center) {
            $id = (string) $center['id'];
            if ($scope !== null && ! in_array($id, $scope, true)) {
                continue;
            }
            $own = $per[$id] ?? $zero;
            if ($own === $zero && ! $descendants) {
                continue;
            }
            $figures = $descendants ? $rollup($id) : $own;
            $rows[] = ['center_id' => $id, 'code' => $center['code'], 'name' => $center['name'], 'center_type' => $center['center_type'], 'depth' => $center['depth'], ...$this->derive($figures)];
            foreach ($own as $k => $v) {
                $totals[$k] = bcadd($totals[$k], $v, 4);
            }
        }
        $totalNet = bcsub(bcadd($totals['direct'], $totals['inflow'], 4), $totals['outflow'], 4);
        foreach ($rows as $i => $row) {
            $rows[$i]['share_pct'] = Money::percent($row['net_cost'], $totalNet);
        }

        return ['rows' => $rows, 'totals' => ['direct_cost' => bcadd($totals['direct'], '0', 2), 'apportioned_in' => bcadd($totals['inflow'], '0', 2), 'apportioned_out' => bcadd($totals['outflow'], '0', 2), 'net_cost' => bcadd($totalNet, '0', 2)]];
    }

    /**
     * @param  array<string, string>  $f
     * @return array<string, string>
     */
    private function derive(array $f): array
    {
        $net = bcsub(bcadd($f['direct'], $f['inflow'], 4), $f['outflow'], 4);
        $variance = bcsub($net, $f['direct'], 4);

        return [
            'direct_cost' => bcadd($f['direct'], '0', 2),
            'apportioned_in' => bcadd($f['inflow'], '0', 2),
            'apportioned_out' => bcadd($f['outflow'], '0', 2),
            'net_cost' => bcadd($net, '0', 2),
            'variance' => bcadd($variance, '0', 2),
            'variance_pct' => Money::percent($variance, $f['direct']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array{direct: string, inflow: string, outflow: string}>
     */
    private function perCenter(string $tenantId, array $filters): array
    {
        $query = DB::table('analytical_entry_lines as l')->join('analytical_entries as e', 'e.id', '=', 'l.analytical_entry_id')
            ->where('l.tenant_id', $tenantId)->whereNull('l.deleted_at')->whereNull('e.deleted_at')
            ->where('e.is_reversed', false)->where('e.entry_type', '!=', EntryType::REVERSAL->value)
            ->whereIn('l.cost_element_type', self::CostElements);
        if (! empty($filters['from'])) {
            $query->where('l.posting_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('l.posting_date', '<=', $filters['to']);
        }
        $out = [];
        foreach ($query->get(['l.cost_center_id', 'l.profit_center_id', 'l.amount', 'e.entry_type']) as $row) {
            $isApportion = $row->entry_type === EntryType::STEP_DOWN_APPORTIONMENT->value;
            foreach ([$row->cost_center_id, $row->profit_center_id] as $center) {
                if ($center === null) {
                    continue;
                }
                $out[(string) $center] ??= ['direct' => '0', 'inflow' => '0', 'outflow' => '0'];
                $amount = (string) $row->amount;
                if (! $isApportion) {
                    $out[(string) $center]['direct'] = bcadd($out[(string) $center]['direct'], $amount, 4);
                } elseif (bccomp($amount, '0', 4) >= 0) {
                    $out[(string) $center]['inflow'] = bcadd($out[(string) $center]['inflow'], $amount, 4);
                } else {
                    $out[(string) $center]['outflow'] = bcadd($out[(string) $center]['outflow'], bcmul($amount, '-1', 4), 4);
                }
            }
        }

        return $out;
    }
}
