<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_delivery_note_ref' => $this->supplier_delivery_note_ref,
            'received_date' => $this->received_date?->toDateString(),
            'received_by_user_id' => $this->received_by_user_id,
            'warehouse_id' => $this->warehouse_id,
            'inspection_status' => $this->inspection_status,
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
