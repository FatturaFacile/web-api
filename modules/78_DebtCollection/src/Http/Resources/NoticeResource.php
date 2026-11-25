<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Notice;

/**
 * @mixin Notice
 */
final class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind,
            'level' => (int) $this->level,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer === null ? null : (trim((string) $this->customer->company_name) !== '' ? (string) $this->customer->company_name : trim($this->customer->first_name.' '.$this->customer->last_name))),
            'jurisdiction' => $this->jurisdiction,
            'currency' => $this->currency,
            'principal' => (string) $this->principal,
            'interest' => (string) $this->interest,
            'fee' => (string) $this->fee,
            'total' => (string) $this->total,
            'new_due_date' => $this->new_due_date?->toDateString(),
            'issued_on' => $this->issued_on->toDateString(),
            'channel' => $this->channel,
            'status' => $this->status,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
            'batch_id' => $this->batch_id,
            'pec_message_id' => $this->pec_message_id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'error' => $this->error,
            'attempts' => (int) $this->attempts,
            'charge_receivable_item_id' => $this->charge_receivable_item_id,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn ($line): array => [
                'receivable_item_id' => $line->receivable_item_id,
                'document_number' => $line->document_number,
                'due_date' => $line->due_date->toDateString(),
                'principal' => (string) $line->principal,
                'interest' => (string) $line->interest,
                'interest_days' => (int) $line->interest_days,
                'rate_percent' => (string) $line->rate_percent,
            ])->all()),
            'receipts' => $this->whenLoaded('receipts', fn (): array => $this->receipts->map(static fn ($receipt): array => [
                'id' => $receipt->id,
                'kind' => $receipt->kind,
                'message_id' => $receipt->message_id,
                'received_at' => $receipt->received_at->toIso8601String(),
                'notes' => $receipt->notes,
            ])->all()),
            'has_pdf' => true,
        ];
    }
}
