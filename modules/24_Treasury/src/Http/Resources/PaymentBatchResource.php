<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\PaymentBatch;

/**
 * @mixin PaymentBatch
 */
final class PaymentBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'bank_account_id' => $this->bank_account_id,
            'batch_reference' => $this->batch_reference,
            'payment_method' => $this->payment_method,
            'execution_date' => $this->execution_date?->toDateString(),
            'total_amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'item_count' => (int) $this->item_count,
            'status' => $this->status,
            'lines' => PaymentLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
