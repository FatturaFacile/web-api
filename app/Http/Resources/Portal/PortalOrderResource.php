<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Support\Portal\PortalDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesOrder
 */
class PortalOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'date' => PortalDate::day($this->date),
            'due_date' => PortalDate::day($this->due_date),
            'currency' => $this->currency ?? 'EUR',
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ])->values()->all()),
            'shipments' => $this->whenLoaded('deliveryNotes', fn () => $this->deliveryNotes
                ->map(fn (DeliveryNote $note): array => PortalDeliveryNoteResource::make($note)->resolve())
                ->values()
                ->all()),
        ];
    }
}
