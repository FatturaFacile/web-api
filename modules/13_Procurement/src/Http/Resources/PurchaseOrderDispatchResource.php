<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseOrderDispatch;

/**
 * A transmission of a purchase order to the supplier.
 *
 * @mixin PurchaseOrderDispatch
 */
final class PurchaseOrderDispatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'channel' => $this->channel,
            'status' => $this->status,
            'recipient' => $this->recipient,
            'message_reference' => $this->message_reference,
            'dispatched_by' => $this->dispatched_by,
            'metadata' => $this->metadata,
            'dispatched_at' => $this->dispatched_at->toIso8601String(),
        ];
    }
}
