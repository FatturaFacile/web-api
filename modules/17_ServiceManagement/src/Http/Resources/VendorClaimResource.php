<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

/**
 * @mixin VendorWarrantyClaim
 */
final class VendorClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $supplier = $this->relationLoaded('supplier') ? $this->supplier : null;
        $rma = $this->relationLoaded('rmaOrder') ? $this->rmaOrder : null;

        return [
            'id' => $this->id,
            'rma_order_id' => $this->rma_order_id,
            'rma_number' => $rma?->rma_number,
            'rma_line_id' => $this->rma_line_id,
            'original_supplier_id' => $this->original_supplier_id,
            'supplier' => $supplier !== null ? ['id' => $supplier->id, 'name' => $supplier->company_name] : null,
            'defective_part_serial' => $this->defective_part_serial,
            'defective_product_id' => $this->defective_product_id,
            'supplier_rma_reference' => $this->supplier_rma_reference,
            'status' => $this->status,
            'claimed_amount' => $this->claimed_amount,
            'currency' => $this->currency,
            'credit_note_reference' => $this->credit_note_reference,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
