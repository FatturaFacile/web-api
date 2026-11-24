<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountApproval;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Support\Quantity;

/**
 * The reconciliation view of a count: system quantity vs counted vs final, variance quantity/value/percentage per
 * line, the totals, the approval level the variances call for under the tenant policy, the approval trail and the links
 * to the adjustment and its General Ledger journal entry. It reveals the theoretical stock, so only managers and the
 * finance approver may ask for it (InventoryCountSessionPolicy::viewVariance).
 */
final class CountVarianceReportService
{
    /**
     * @return array{summary: array<string, mixed>, lines: list<InventoryCountLine>, approvals: list<InventoryCountApproval>, adjustment: ?array<string, mixed>}
     */
    public function report(InventoryCountSession $session): array
    {
        $lines = $session->lines()->with(['item', 'bin'])->orderBy('id')->get();
        $policy = InventoryCountPolicy::forTenant((string) $session->tenant_id);

        $counted = $lines->filter(static fn (InventoryCountLine $line): bool => $line->counted_quantity !== null);
        $differing = $counted->filter(static fn (InventoryCountLine $line): bool => ! Quantity::isZero((string) $line->discrepancy_quantity));

        $gross = '0.00';
        $net = '0.00';
        $systemTotal = '0.00';

        foreach ($lines as $line) {
            $systemTotal = Quantity::add($systemTotal, (string) ($line->system_value ?? '0'), 2);
        }

        foreach ($differing as $line) {
            $gross = Quantity::add($gross, Quantity::abs((string) $line->discrepancy_valuation, 2), 2);
            $net = Quantity::add($net, (string) $line->discrepancy_valuation, 2);
        }

        $level = CountVarianceMath::approvalLevel($policy, $differing->map(static fn (InventoryCountLine $line): array => [
            'variance_value' => (string) $line->discrepancy_valuation,
            'variance_percentage' => $line->variance_percentage === null ? null : (string) $line->variance_percentage,
            'variance_quantity' => (string) $line->discrepancy_quantity,
        ])->values()->all());

        $adjustment = $session->inventory_adjustment_id !== null ? InventoryAdjustment::query()->find($session->inventory_adjustment_id) : null;

        return [
            'summary' => [
                'lines' => $lines->count(),
                'counted_lines' => $counted->count(),
                'uncounted_lines' => $lines->count() - $counted->count(),
                'differing_lines' => $differing->count(),
                'recount_pending_lines' => $lines->where('recount_required', true)->count(),
                'system_value_total' => $systemTotal,
                'gross_variance_value' => $gross,
                'net_variance_value' => $net,
                'approval_mode' => $policy->approval_mode,
                'suggested_approval_level' => $policy->isMultiLevel() ? $level : null,
                'within_tolerance' => $level === 0,
            ],
            'lines' => $lines->all(),
            'approvals' => $adjustment === null ? [] : InventoryCountApproval::query()->where('inventory_adjustment_id', $adjustment->id)->orderBy('level')->get()->all(),
            'adjustment' => $adjustment === null ? null : [
                'id' => (string) $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                'status' => $adjustment->status,
                'net_valuation_change' => (string) $adjustment->net_valuation_change,
                'journal_entry_id' => $adjustment->journal_entry_id,
                'gl_posted_at' => $adjustment->gl_posted_at,
            ],
        ];
    }
}
