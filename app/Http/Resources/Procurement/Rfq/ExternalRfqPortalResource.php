<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * External Supplier Bidding Portal read model.
 * Strictly omits internal target_unit_price, evaluation_weights, internal notes, and competitor bids.
 */
final class ExternalRfqPortalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{rfq: RequestForQuotation, invitation: RfqSupplierInvitation, buyer_company: array<string, mixed>, bid: ?SupplierBid, preferred_locale: string, is_open: bool, can_submit: bool} $payload */
        $payload = $this->resource;
        $rfq = $payload['rfq'];
        $invitation = $payload['invitation'];
        $bid = $payload['bid'];

        return [
            'rfq' => [
                'id' => (string) $rfq->id,
                'rfq_number' => (string) $rfq->rfq_number,
                'title' => (string) $rfq->title,
                'description' => $rfq->description,
                'status' => (string) $rfq->status,
                'currency_code' => $rfq->effectiveCurrencyCode(),
                'incoterms' => $rfq->incoterms,
                'submission_deadline' => $rfq->effectiveDeadline()->toIso8601String(),
                'expected_delivery_date' => $rfq->expected_delivery_date?->toDateString(),
                'sealed_bidding' => (bool) $rfq->sealed_bidding,
                'lines' => $rfq->lines->map(static fn (RfqLine $line): array => [
                    'id' => (string) $line->id,
                    'line_number' => (int) $line->line_number,
                    'item_code' => $line->custom_item_code ?? $line->internal_sku,
                    'description' => (string) $line->description,
                    'technical_specifications' => $line->technical_specifications,
                    'quantity' => (string) $line->quantity,
                    'unit_of_measure' => (string) $line->uom,
                    'requested_delivery_date' => $line->required_by_date?->toDateString(),
                ])->values()->all(),
                'attachments' => $rfq->attachments->map(static fn (RfqAttachment $attachment): array => [
                    'id' => (string) $attachment->id,
                    'file_name' => (string) $attachment->file_name,
                    'mime_type' => (string) $attachment->mime_type,
                    'file_size_bytes' => (int) $attachment->file_size_bytes,
                ])->values()->all(),
            ],
            'buyer_company' => $payload['buyer_company'],
            'invitation' => [
                'id' => (string) $invitation->id,
                'supplier_id' => (string) $invitation->supplier_id,
                'supplier_name' => $invitation->supplier?->company_name,
                'status' => (string) $invitation->status,
                'token_expires_at' => $invitation->token_expires_at?->toIso8601String(),
                'first_viewed_at' => $invitation->first_viewed_at?->toIso8601String(),
                'submitted_at' => $invitation->submitted_at?->toIso8601String(),
                'declined_reason' => $invitation->declined_reason,
            ],
            'bid' => $bid !== null ? (new SupplierBidResource($bid))->toArray($request) : null,
            'preferred_locale' => $payload['preferred_locale'],
            'is_open' => $payload['is_open'],
            'can_submit' => $payload['can_submit'],
        ];
    }
}
