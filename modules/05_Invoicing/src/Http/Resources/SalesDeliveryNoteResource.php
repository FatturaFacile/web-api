<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesDeliveryNote;

/**
 * @mixin SalesDeliveryNote
 */
final class SalesDeliveryNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contact_id' => $this->contact_id,
            'sales_order_id' => $this->sales_order_id,
            'warehouse_id' => $this->warehouse_id,
            'ddt_number' => $this->ddt_number,
            'date' => $this->date?->toDateString(),
            'transport_reason' => $this->transport_reason,
            'packages_count' => $this->packages_count,
            'gross_weight_kg' => (float) $this->gross_weight_kg,
            'carrier_name' => $this->carrier_name,
            'status' => $this->status,
            'shipping_status' => $this->shipping_status,
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'items' => $this->whenLoaded('items'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
