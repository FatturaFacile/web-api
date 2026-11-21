<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\SupplierBid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierBid
 */
final class SupplierBidResource extends JsonResource
{
    public static bool $sealedRedaction = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (self::$sealedRedaction) {
            return [
                'id' => (string) $this->id,
                'rfq_id' => (string) $this->rfq_id,
                'invitation_id' => $this->invitation_id,
                'supplier_id' => (string) $this->supplier_id,
                'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
                'status' => (string) $this->status,
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'is_redacted' => true,
            ];
        }

        return [
            'id' => (string) $this->id,
            'rfq_id' => (string) $this->rfq_id,
            'invitation_id' => $this->invitation_id,
            'supplier_id' => (string) $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
            'supplier_quote_reference' => $this->quote_reference,
            'quote_reference' => $this->quote_reference,
            'quote_date' => $this->quote_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'currency_code' => (string) $this->currency,
            'currency' => (string) $this->currency,
            'exchange_rate_to_rfq' => (string) $this->exchange_rate_to_rfq,
            'payment_terms_days' => (int) $this->payment_terms_days,
            'incoterms' => $this->incoterms,
            'shipping_cost' => (string) $this->shipping_cost,
            'lead_time_days' => $this->lead_time_days,
            'status' => (string) $this->status,
            'total_amount' => (string) $this->total_amount,
            'total_net_amount' => (string) $this->total_net_amount,
            'total_landed_cost' => (string) $this->total_landed_cost,
            'weighted_overall_score' => $this->weighted_overall_score !== null ? (string) $this->weighted_overall_score : null,
            'general_notes' => $this->notes,
            'quote_attachment_file_name' => $this->quote_attachment_file_name,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'is_redacted' => false,
            'lines' => SupplierBidLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
