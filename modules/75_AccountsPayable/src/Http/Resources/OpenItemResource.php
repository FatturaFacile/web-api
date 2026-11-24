<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApOpenItem;

/**
 * A supplier open item with its cash-discount figures (`skonto_*`, `net_payable_with_skonto`, `standard_amount`) and the
 * reason it cannot be paid right now (`payment_block`: disputed, administrative_block, invoice_not_approved,
 * in_payment_run, settled, or null).
 *
 * @mixin ApOpenItem
 */
final class OpenItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $supplier = $this->relationLoaded('supplier') ? $this->supplier : null;

        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $supplier === null ? null : ($supplier->company_name ?: (trim($supplier->first_name.' '.$supplier->last_name) ?: null)),
            'passive_invoice_id' => $this->passive_invoice_id,
            'source' => $this->source,
            'kind' => $this->kind,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'currency' => $this->currency,
            'original_amount' => (string) $this->original_amount,
            'paid_amount' => (string) $this->paid_amount,
            'discount_taken_amount' => (string) $this->discount_taken_amount,
            'open_amount' => (string) $this->open_amount,
            'creditor_iban' => $this->creditor_iban,
            'status' => $this->status,
            'hold' => $this->isHeld() ? [
                'type' => $this->status === 'disputed' ? 'dispute' : 'administrative_block',
                'reason' => $this->hold_reason,
                'held_at' => $this->held_at?->toIso8601String(),
                'held_by' => $this->held_by,
            ] : null,
            'payment_block' => $this->getAttribute('payment_block'),
            'payment_run_id' => $this->getAttribute('payment_run_id'),
            'days_past_due' => max(0, $this->daysPastDue(now())),
            'notes' => $this->notes,
        ] + (array) ($this->getAttribute('skonto') ?? []) + [
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
