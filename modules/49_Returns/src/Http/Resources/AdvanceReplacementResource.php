<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\Returns\Models\AdvanceReplacement;

/**
 * @mixin AdvanceReplacement
 */
final class AdvanceReplacementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOverdue = $this->status === AdvanceReplacement::StatusPendingDefectiveReturn
            && $this->return_due_on->lt(Carbon::today());

        return [
            'id' => $this->id,
            'replacement_number' => $this->replacement_number,
            'customer_return_id' => $this->customer_return_id,
            'rma_id' => $this->rma_id,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->contact?->company_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'original_invoice_id' => $this->original_invoice_id,
            'faulty_product_id' => $this->faulty_product_id,
            'faulty_product_name' => $this->faultyProduct?->name,
            'faulty_serial_number' => $this->faulty_serial_number,
            'replacement_product_id' => $this->replacement_product_id,
            'replacement_product_name' => $this->replacementProduct?->name,
            'replacement_serial_number' => $this->replacement_serial_number,
            'replacement_unit_type' => $this->replacement_unit_type,
            'refurbished_item_id' => $this->refurbished_item_id,
            'quantity' => $this->quantity,
            'warehouse_id' => $this->warehouse_id,
            'outbound_stock_movement_id' => $this->outbound_stock_movement_id,
            'status' => $this->status,
            'is_overdue' => $isOverdue,
            'shipped_on' => $this->shipped_on->toDateString(),
            'sla_days' => $this->sla_days,
            'return_due_on' => $this->return_due_on->toDateString(),
            'defective_received_at' => $this->defective_received_at?->toIso8601String(),
            'penalty_amount' => $this->penalty_amount,
            'currency' => $this->currency,
            'penalty_invoice_id' => $this->penalty_invoice_id,
            'penalty_invoice_number' => $this->penalty_invoice_number,
            'billed_at' => $this->billed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
