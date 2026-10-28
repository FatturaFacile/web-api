<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\ReceivableCollection;

/**
 * @mixin ReceivableCollection
 */
final class ReceivableCollectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'invoice_id' => $this->invoice_id,
            'expected_amount' => (float) $this->expected_amount,
            'collected_amount' => (float) $this->collected_amount,
            'due_date' => $this->due_date?->toDateString(),
            'collection_date' => $this->collection_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'aging_bucket' => $this->aging_bucket,
            'dunning_level' => (int) $this->dunning_level,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
