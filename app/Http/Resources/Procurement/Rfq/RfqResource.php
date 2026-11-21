<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full RFQ representation for authenticated buyers (preserving legacy keys + Mod 045 extensions).
 *
 * @mixin RequestForQuotation
 */
final class RfqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isSealed = $this->isSealedNow();
        $previousRedaction = SupplierBidResource::$sealedRedaction;
        SupplierBidResource::$sealedRedaction = $isSealed;

        try {
            $bids = $this->whenLoaded('bids', function () use ($request): array {
                return $this->bids->map(
                    static fn (SupplierBid $bid): array => (new SupplierBidResource($bid))->toArray($request)
                )->all();
            });
        } finally {
            SupplierBidResource::$sealedRedaction = $previousRedaction;
        }

        return [
            'id' => (string) $this->id,
            'rfq_number' => (string) $this->rfq_number,
            'title' => (string) $this->title,
            'description' => $this->description,
            'status' => (string) $this->status,
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'response_deadline' => $this->response_deadline?->toDateString(),
            'submission_deadline' => $this->effectiveDeadline()->toIso8601String(),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'currency' => (string) $this->currency,
            'currency_code' => $this->effectiveCurrencyCode(),
            'incoterms' => $this->incoterms,
            'evaluation_weights' => $this->weights()->toArray(),
            'sealed_bidding' => (bool) $this->sealed_bidding,
            'is_sealed' => $isSealed,
            'buyer_user_id' => $this->buyer_user_id,
            'buyer_name' => $this->whenLoaded('buyer', fn () => $this->buyer?->name),
            'cost_center_id' => $this->cost_center_id,
            'award_mode' => $this->award_mode,
            'awarded_supplier_id' => $this->awarded_supplier_id,
            'awarded_quote_id' => $this->awarded_quote_id,
            'purchase_order_id' => $this->purchase_order_id,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'awarded_by_user_id' => $this->awarded_by_user_id,
            'notes' => $this->notes,
            'lines_count' => $this->whenCounted('lines'),
            'invited_count' => $this->whenCounted('invitations', fn (): int => (int) ($this->getAttribute('invited_count') ?? $this->invitations_count ?? 0)),
            'bids_received_count' => (int) ($this->getAttribute('bids_received_count') ?? 0),
            'lines' => RfqLineResource::collection($this->whenLoaded('lines')),
            'attachments' => RfqAttachmentResource::collection($this->whenLoaded('attachments')),
            'suppliers' => RfqInvitationResource::collection($this->whenLoaded('invitations')),
            'invitations' => RfqInvitationResource::collection($this->whenLoaded('invitations')),
            'bids' => $bids,
            'quotes' => $bids,
            'source_requisition_links' => $this->whenLoaded('sourceRequisitionLinks', fn () => $this->sourceRequisitionLinks->map(static fn (PurchaseRequisitionDocumentLink $link): array => [
                'id' => (string) $link->id,
                'purchase_requisition_id' => (string) $link->purchase_requisition_id,
                'purchase_requisition_line_id' => (string) $link->purchase_requisition_line_id,
                'requisition_number' => $link->relationLoaded('requisition') ? $link->requisition?->requisition_number : null,
                'target_document_line_id' => $link->target_document_line_id,
                'converted_quantity' => (string) $link->converted_quantity,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
