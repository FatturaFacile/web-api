<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * @mixin ReceivableItem
 */
final class ReceivableItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = $request->attributes->get('receivables_today') ?? CarbonImmutable::today();

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'invoice_id' => $this->invoice_id,
            'sequence' => (int) $this->sequence,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'currency' => $this->currency,
            'original_amount' => (string) $this->original_amount,
            'open_amount' => (string) $this->open_amount,
            'settled_amount' => bcsub((string) $this->original_amount, (string) $this->open_amount, 2),
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'value_date' => $this->value_date?->toDateString(),
            'status' => $this->effectiveStatus($today),
            'stored_status' => $this->status,
            'days_past_due' => $this->daysPastDue($today),
            'dispute_reason' => $this->dispute_reason,
            'allocations' => ReceivableAllocationResource::collection($this->whenLoaded('allocations')),
        ];
    }
}
