<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sales return with its lines, as the sales API returns it.
 *
 * @mixin SalesReturn
 */
class SalesReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'date' => $this->date->toDateString(),
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn (): array => ['id' => $this->contact->id, 'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name)]),
            'sales_order_id' => $this->sales_order_id,
            'delivery_note_id' => $this->delivery_note_id,
            'invoice_id' => $this->invoice_id,
            'warehouse_id' => $this->warehouse_id,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'credit_note_id' => $this->credit_note_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'credited_at' => $this->credited_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(static fn (SalesReturnItem $item): array => [
                'id' => $item->id,
                'delivery_note_item_id' => $item->delivery_note_item_id,
                'invoice_item_id' => $item->invoice_item_id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'quantity_received' => (string) $item->quantity_received,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'discount_percentage' => (string) $item->discount_percentage,
                'condition' => $item->condition,
                'restock' => $item->restock,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
