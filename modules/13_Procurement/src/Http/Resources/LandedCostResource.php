<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\LandedCostAllocation;

/**
 * A landed cost with its allocation to goods receipt lines.
 *
 * @mixin LandedCost
 */
final class LandedCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'landed_cost_number' => $this->landed_cost_number,
            'cost_type' => $this->cost_type,
            'allocation_method' => $this->allocation_method,
            'status' => $this->status,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'cost_date' => $this->cost_date->toDateString(),
            'description' => $this->description,
            'passive_invoice_id' => $this->passive_invoice_id,
            'supplier_id' => $this->supplier_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(static fn (LandedCostAllocation $allocation): array => [
                'purchase_receipt_id' => (string) $allocation->purchase_receipt_id,
                'purchase_receipt_line_id' => (string) $allocation->purchase_receipt_line_id,
                'stock_item_id' => $allocation->stock_item_id,
                'basis_value' => (string) $allocation->basis_value,
                'allocated_amount' => (string) $allocation->allocated_amount,
            ])->all()),
        ];
    }
}
