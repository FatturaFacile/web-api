<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\CustomerLedgerEntry;

/**
 * @mixin CustomerLedgerEntry
 */
final class CustomerLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'invoice_id' => $this->invoice_id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'entry_type' => $this->entry_type,
            'document_reference' => $this->document_reference,
            'debit_amount' => (float) $this->debit_amount,
            'credit_amount' => (float) $this->credit_amount,
            'balance_after' => (float) $this->balance_after,
            'cleared_amount' => (float) $this->cleared_amount,
            'outstanding_amount' => (float) $this->outstanding_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'cleared_at' => $this->cleared_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
