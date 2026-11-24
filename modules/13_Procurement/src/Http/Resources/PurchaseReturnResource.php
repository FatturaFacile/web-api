<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status,
            'total_amount' => (float) $this->total_amount,
            'reason' => $this->reason,
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
