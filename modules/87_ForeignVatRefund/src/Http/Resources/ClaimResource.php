<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ForeignVatRefund\Models\VatRefundClaim;

/**
 * @mixin VatRefundClaim
 */
final class ClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_number' => $this->claim_number,
            'home_country_code' => $this->home_country_code,
            'target_country_code' => $this->target_country_code,
            'regime_type' => $this->regime_type->value,
            'period_type' => $this->period_type->value,
            'fiscal_year' => $this->fiscal_year,
            'period_start_date' => $this->period_start_date->format('Y-m-d'),
            'period_end_date' => $this->period_end_date->format('Y-m-d'),
            'statutory_deadline_date' => $this->statutory_deadline_date->format('Y-m-d'),
            'currency_code' => $this->currency_code,
            'total_requested_amount' => (string) $this->total_requested_amount,
            'total_approved_amount' => (string) $this->total_approved_amount,
            'total_rejected_amount' => (string) $this->total_rejected_amount,
            'total_refunded_amount' => (string) $this->total_refunded_amount,
            'carried_base_amount' => (string) $this->carried_base_amount,
            'refund_exchange_rate' => $this->refund_exchange_rate === null ? null : (string) $this->refund_exchange_rate,
            'fiscal_representative_mandate_id' => $this->fiscal_representative_mandate_id,
            'status' => $this->status->value,
            'allowed_transitions' => array_map(static fn ($status): string => $status->value, $this->status->next()),
            'locked' => $this->status->locksItems(),
            'submission_date' => $this->submission_date?->toIso8601String(),
            'protocol_number' => $this->protocol_number,
            'has_xml' => $this->generated_xml_path !== null,
            'has_pdf_dossier' => $this->generated_pdf_dossier_path !== null,
            'has_zip_bundle' => $this->zip_bundle_path !== null,
            'bank_account_id' => $this->bank_account_id,
            'rejection_journal_entry_id' => $this->rejection_journal_entry_id,
            'reconciliation_journal_entry_id' => $this->reconciliation_journal_entry_id,
            'refunded_on' => $this->refunded_on?->format('Y-m-d'),
            'notes' => $this->notes,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => LedgerEntryResource::collection($this->items)),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(static fn ($event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'payload' => $event->payload,
                'user_id' => $event->user_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
