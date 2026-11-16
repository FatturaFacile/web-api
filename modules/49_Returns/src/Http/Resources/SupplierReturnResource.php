<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Models\SupplierReturnLine;

/**
 * A return to the supplier with its lines.
 *
 * @mixin SupplierReturn
 */
final class SupplierReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'status' => $this->status,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->company_name,
            'warehouse_id' => $this->warehouse_id,
            'rma_id' => $this->rma_id,
            'reason_code' => $this->reason_code,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'currency' => $this->currency,
            'credit_expected' => $this->credit_expected,
            'credit_received' => $this->credit_received,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'credited_at' => $this->credited_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'notes' => $this->notes,
            'allowed_transitions' => SupplierReturn::Transitions[$this->status] ?? [],
            'lines' => $this->relationLoaded('lines') ? $this->lines->map(static fn (SupplierReturnLine $line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost,
                'goods_receipt_item_id' => $line->goods_receipt_item_id,
                'rma_line_id' => $line->rma_line_id,
                'serial_number' => $line->serial_number,
                'lot_number' => $line->lot_number,
                'stock_movement_id' => $line->stock_movement_id,
            ])->values()->all() : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
