<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Support\Money;

/**
 * A production order with its quantities, standard and actual costs, variances and, when loaded, its component lines
 * and operations. Amounts and quantities are decimal strings.
 *
 * @mixin MfgProductionOrder
 */
final class MfgProductionOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'mfg_item_id' => $this->mfg_item_id,
            'bom_id' => $this->bom_id,
            'routing_id' => $this->routing_id,
            'project_id' => $this->project_id,
            'sales_order_id' => $this->sales_order_id,
            'planned_order_id' => $this->planned_order_id,
            'warehouse_id' => $this->warehouse_id,
            'receipt_warehouse_id' => $this->receipt_warehouse_id,
            'status' => $this->status,
            'target_quantity' => $this->target_quantity,
            'completed_quantity' => $this->completed_quantity,
            'scrapped_quantity' => $this->scrapped_quantity,
            'uom' => $this->uom,
            'planned_start_date' => $this->planned_start_date->toDateString(),
            'planned_end_date' => $this->planned_end_date->toDateString(),
            'actual_start_date' => $this->actual_start_date?->toIso8601String(),
            'actual_end_date' => $this->actual_end_date?->toIso8601String(),
            'standard_unit_cost' => Money::round($this->resource->standardUnitCost(), 6),
            'standard_cost_total' => $this->standard_cost_total,
            'actual_material_cost' => $this->actual_material_cost,
            'actual_labor_cost' => $this->actual_labor_cost,
            'actual_overhead_cost' => $this->actual_overhead_cost,
            'actual_subcontract_cost' => $this->actual_subcontract_cost,
            'received_value' => $this->received_value,
            'wip_balance' => $this->status === 'closed' ? '0.00' : Money::round($this->resource->wipBalance()),
            'actual_cost_total' => $this->actual_cost_total,
            'variance_total' => $this->variance_total,
            'material_variance' => $this->material_variance,
            'labor_variance' => $this->labor_variance,
            'overhead_variance' => $this->overhead_variance,
            'subcontract_variance' => $this->subcontract_variance,
            'variance_journal_entry_id' => $this->variance_journal_entry_id,
            'released_at' => $this->released_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (MfgProductionOrderLine $line): array => [
                'id' => $line->id,
                'mfg_item_id' => $line->mfg_item_id,
                'issue_method' => $line->issue_method,
                'operation_sequence' => $line->operation_sequence,
                'quantity_per_unit' => $line->quantity_per_unit,
                'required_quantity' => $line->required_quantity,
                'reserved_quantity' => $line->allocated_quantity,
                'issued_quantity' => $line->consumed_quantity,
                'open_quantity' => Money::qty($line->openQuantity()),
                'uom' => $line->uom,
                'standard_unit_cost' => $line->unit_cost,
                'issued_cost' => $line->issued_cost,
                'warehouse_id' => $line->warehouse_id,
            ])->values()->all()),
            'operations' => $this->whenLoaded('operations', fn () => $this->operations->map(static fn (MfgProductionOrderOperation $operation): array => [
                'id' => $operation->id,
                'sequence' => $operation->sequence,
                'operation_code' => $operation->operation_code,
                'name' => $operation->name,
                'work_center_id' => $operation->work_center_id,
                'status' => $operation->status,
                'is_subcontracted' => $operation->is_subcontracted,
                'scheduled_date' => $operation->scheduled_date?->toDateString(),
                'planned_setup_minutes' => $operation->planned_setup_minutes,
                'planned_run_minutes' => $operation->planned_run_minutes,
                'planned_labor_minutes' => $operation->planned_labor_minutes,
                'actual_setup_minutes' => $operation->actual_setup_minutes,
                'actual_run_minutes' => $operation->actual_run_minutes,
                'actual_labor_minutes' => $operation->actual_labor_minutes,
                'labor_rate' => $operation->labor_rate,
                'overhead_rate' => $operation->overhead_rate,
                'actual_labor_cost' => $operation->actual_labor_cost,
                'actual_overhead_cost' => $operation->actual_overhead_cost,
                'completed_quantity' => $operation->completed_quantity,
                'scrapped_quantity' => $operation->scrapped_quantity,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
