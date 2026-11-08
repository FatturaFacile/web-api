<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;

/**
 * A billed (or failed) period of a subscription and its invoice or credit note.
 *
 * @mixin InvoiceSchedule
 */
final class InvoiceScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'billing_kind' => $this->billing_kind,
            'status' => $this->status,
            'service_period_start' => $this->service_period_start?->toDateString(),
            'service_period_end' => $this->service_period_end?->toDateString(),
            'scheduled_billing_date' => $this->scheduled_billing_date?->toDateString(),
            'net_amount' => (string) $this->getRawOriginal('net_amount'),
            'gross_amount' => (string) $this->getRawOriginal('estimated_amount'),
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'invoice_status' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->status),
            'payment_status' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->payment_status),
            'credit_note_id' => $this->credit_note_id,
            'billing_run_id' => $this->billing_run_id,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
