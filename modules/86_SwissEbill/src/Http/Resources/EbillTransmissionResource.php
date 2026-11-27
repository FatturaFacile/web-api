<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissEbill\Application\Services\EbillPreflightService;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * @mixin EbillTransmission
 */
final class EbillTransmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            'contact_id' => $this->contact_id,
            'customer_name' => $this->whenLoaded('contact', fn () => $this->contact !== null ? EbillPreflightService::displayName($this->contact) : null),
            'supersedes_id' => $this->supersedes_id,
            'status' => $this->status->value,
            'channel_type' => $this->channel_type?->value,
            'attempt_count' => $this->attempt_count,
            'biller_id' => $this->biller_id,
            'recipient_id' => $this->recipient_id,
            'six_transaction_id' => $this->six_transaction_id,
            'submission_timestamp' => $this->submission_timestamp?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'scheduled_payment_date' => $this->scheduled_payment_date?->toDateString(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_code' => $this->rejection_code,
            'rejection_reason' => $this->rejection_reason,
            'error_code' => $this->error_code,
            'error_details' => $this->error_details,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'camt054_entry_id' => $this->camt054_entry_id,
            'settlement_value_date' => $this->settlement_value_date?->toDateString(),
            'settled_amount' => (float) $this->settled_amount,
            'amount_discrepancy' => $this->amount_discrepancy !== null ? (float) $this->amount_discrepancy : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'invoice_amount' => (float) $this->invoice_amount,
            'currency' => $this->currency,
            'ebinterface_version' => $this->ebinterface_version,
            'xml_sha256' => $this->xml_sha256,
            'pdf_sha256' => $this->pdf_sha256,
            'pdf_size_bytes' => $this->pdf_size_bytes,
            'can_cancel' => $this->status->canTransitionTo(EbillTransmissionStatus::Cancelled),
            'can_retry' => $this->status === EbillTransmissionStatus::FailedTechnical,
            'can_revise' => in_array($this->status, [EbillTransmissionStatus::RejectedByDebtor, EbillTransmissionStatus::Cancelled], true),
            'events' => EbillTransmissionEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
