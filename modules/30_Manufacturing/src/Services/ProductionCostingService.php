<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgOperationConfirmation;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Support\Money;

/**
 * Cost report of a production order. Standards are the unit costs frozen at release times the planned quantity and
 * the quantity received; actuals are what issues, confirmations and subcontract receipts posted to WIP. Before the
 * close the variance is the live difference between actual and the standard of the output; after it, the settled one.
 */
final readonly class ProductionCostingService implements ProductionCostingServiceInterface
{
    public function costReport(MfgProductionOrder $order): array
    {
        $isClosed = $order->status === 'closed';
        $target = Money::of($order->target_quantity);
        $output = Money::of($order->completed_quantity);
        $elements = [];
        $totals = ['standard_for_target' => '0', 'standard_for_output' => '0', 'actual' => '0', 'variance' => '0'];

        $data = [
            'material' => [$order->std_unit_material_cost, $order->actual_material_cost, $order->material_variance],
            'labor' => [$order->std_unit_labor_cost, $order->actual_labor_cost, $order->labor_variance],
            'overhead' => [$order->std_unit_overhead_cost, $order->actual_overhead_cost, $order->overhead_variance],
            'subcontract' => [$order->std_unit_subcontract_cost, $order->actual_subcontract_cost, $order->subcontract_variance],
        ];

        foreach ($data as $element => [$unit, $actual, $settled]) {
            $forTarget = Money::round(Money::mul($unit, $target));
            $forOutput = Money::round(Money::mul($unit, $output));
            $variance = $isClosed ? Money::round($settled) : Money::round(Money::sub($actual, $forOutput));

            $elements[$element] = [
                'standard_unit_cost' => Money::round($unit, 6),
                'standard_for_target' => $forTarget,
                'standard_for_output' => $forOutput,
                'actual' => Money::round($actual),
                'variance' => $variance,
            ];

            $totals['standard_for_target'] = Money::add($totals['standard_for_target'], $forTarget);
            $totals['standard_for_output'] = Money::add($totals['standard_for_output'], $forOutput);
            $totals['actual'] = Money::add($totals['actual'], $actual);
            $totals['variance'] = Money::add($totals['variance'], $variance);
        }

        $journalIds = MfgMaterialMovement::query()->where('production_order_id', $order->id)->whereNotNull('journal_entry_id')->pluck('journal_entry_id')
            ->merge(MfgOperationConfirmation::query()->where('production_order_id', $order->id)->whereNotNull('journal_entry_id')->pluck('journal_entry_id'))
            ->when($order->variance_journal_entry_id !== null, static fn ($ids) => $ids->push($order->variance_journal_entry_id))
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'production_order_id' => (string) $order->id,
            'status' => $order->status,
            'currency' => TenantAccountingCurrency::of((string) $order->tenant_id),
            'target_quantity' => Money::qty($target),
            'received_quantity' => Money::qty($output),
            'scrapped_quantity' => Money::qty($order->scrapped_quantity),
            'standard_unit_cost' => Money::round($order->standardUnitCost(), 6),
            'elements' => $elements,
            'totals' => [
                'standard_for_target' => Money::round($totals['standard_for_target']),
                'standard_for_output' => Money::round($totals['standard_for_output']),
                'actual' => Money::round($totals['actual']),
                'received_value' => Money::round($order->received_value),
                'wip_balance' => $isClosed ? '0.00' : Money::round($order->wipBalance()),
                'variance' => Money::round($totals['variance']),
            ],
            'journal_entry_ids' => $journalIds,
            'is_closed' => $isClosed,
        ];
    }
}
