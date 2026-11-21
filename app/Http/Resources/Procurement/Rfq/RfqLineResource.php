<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\RfqLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RfqLine
 */
final class RfqLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rfq_id' => (string) $this->rfq_id,
            'line_number' => (int) $this->line_number,
            'source_requisition_line_id' => $this->purchase_requisition_line_id,
            'purchase_requisition_line_id' => $this->purchase_requisition_line_id,
            'item_id' => $this->item_id,
            'custom_item_code' => $this->custom_item_code,
            'internal_sku' => $this->internal_sku ?? $this->custom_item_code,
            'description' => (string) $this->description,
            'technical_specifications' => $this->technical_specifications,
            'quantity' => (string) $this->quantity,
            'uom' => (string) $this->uom,
            'unit_of_measure' => (string) $this->uom,
            'target_unit_price' => $this->target_unit_price !== null ? (string) $this->target_unit_price : null,
            'requested_delivery_date' => $this->required_by_date?->toDateString(),
            'required_by_date' => $this->required_by_date?->toDateString(),
            'awarded_supplier_id' => $this->awarded_supplier_id,
            'awarded_supplier_name' => $this->whenLoaded('awardedSupplier', fn () => $this->awardedSupplier?->company_name),
            'awarded_bid_line_id' => $this->awarded_bid_line_id,
            'generated_purchase_order_id' => $this->generated_purchase_order_id,
            'generated_purchase_order_number' => $this->whenLoaded('generatedPurchaseOrder', fn () => $this->generatedPurchaseOrder->po_number ?? $this->generatedPurchaseOrder?->order_number),
        ];
    }
}
