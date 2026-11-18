<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\DeliveryNote;
use App\Support\Portal\CarrierTrackingUrl;
use App\Support\Portal\PortalDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryNote
 */
class PortalDeliveryNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ddt_number' => $this->ddt_number,
            'sales_order_id' => $this->sales_order_id,
            'date' => PortalDate::day($this->date),
            'packages_count' => $this->packages_count,
            'shipping_status' => $this->shipping_status,
            'shipped_at' => PortalDate::iso($this->shipped_at),
            'delivered_at' => PortalDate::iso($this->delivered_at),
            'shipment' => [
                'carrier_name' => $this->carrier_name,
                'tracking_number' => $this->tracking_number,
                'tracking_url' => CarrierTrackingUrl::for($this->carrier_name, $this->tracking_number),
            ],
        ];
    }
}
