<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseOrderDropship;

/**
 * A dropshipment: destination, custom DDT, delivery proof, virtual stock and billing authorisation.
 *
 * @mixin PurchaseOrderDropship
 */
final class PurchaseOrderDropshipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'sales_order_id' => $this->sales_order_id,
            'status' => $this->status,
            'recipient_name' => $this->recipient_name,
            'recipient_street' => $this->recipient_street,
            'recipient_house_number' => $this->recipient_house_number,
            'recipient_postal_code' => $this->recipient_postal_code,
            'recipient_city' => $this->recipient_city,
            'recipient_region' => $this->recipient_region,
            'recipient_country_code' => $this->recipient_country_code,
            'recipient_phone' => $this->recipient_phone,
            'recipient_email' => $this->recipient_email,
            'delivery_notes' => $this->delivery_notes,
            'ddt_number' => $this->ddt_number,
            'ddt_payload' => $this->ddt_payload,
            'supplier_shipment_reference' => $this->supplier_shipment_reference,
            'delivery_proof_reference' => $this->delivery_proof_reference,
            'billing_authorized' => $this->billing_authorized,
            'billing_authorization_reference' => $this->billing_authorization_reference,
            'ddt_issued_at' => $this->ddt_issued_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'billing_authorized_at' => $this->billing_authorized_at?->toIso8601String(),
            'virtual_stock_movements' => $this->whenLoaded('movements', fn () => $this->movements->map(static fn ($movement): array => [
                'purchase_order_line_id' => (string) $movement->purchase_order_line_id,
                'direction' => $movement->direction,
                'quantity_base' => (string) $movement->quantity_base,
                'base_uom' => $movement->base_uom,
                'unit_cost_base' => (string) $movement->unit_cost_base,
                'valued_amount' => (string) $movement->valued_amount,
                'moved_at' => $movement->moved_at->toIso8601String(),
            ])->all()),
        ];
    }
}
