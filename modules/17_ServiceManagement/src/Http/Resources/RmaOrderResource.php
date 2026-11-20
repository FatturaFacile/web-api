<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\RmaStatusHistory;
use Modules\ServiceManagement\Services\ContactNameResolver;

/**
 * @mixin DepotRepairRmaOrder
 */
final class RmaOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['passport', 'customer', 'lines', 'history', 'vendorClaims.supplier', 'loaner.rmaOrder']);

        $customer = $this->customer;
        $loaner = $this->loaner;
        $this->vendorClaims->each(fn ($claim) => $claim->setRelation('rmaOrder', $this->resource));

        return [
            'id' => $this->id,
            'rma_number' => $this->rma_number,
            'status' => $this->status,
            'warranty_status' => $this->warranty_status,
            'equipment_passport_id' => $this->equipment_passport_id,
            'serial_number' => $this->passport?->serial_number,
            'customer_id' => $this->customer_id,
            'customer' => $customer !== null ? [
                'id' => $customer->id,
                'name' => ContactNameResolver::nameOf($customer),
            ] : null,
            'warranty_evaluated_at' => $this->warranty_evaluated_at?->toIso8601String(),
            'warranty_basis' => $this->warranty_basis,
            'reported_symptom' => $this->reported_symptom,
            'received_at' => $this->received_at?->toIso8601String(),
            'receipt_condition_notes' => $this->receipt_condition_notes,
            'receipt_photos' => $this->receipt_photos ?? [],
            'lab_bench_diagnostic_notes' => $this->lab_bench_diagnostic_notes,
            'diagnosed_at' => $this->diagnosed_at?->toIso8601String(),
            'estimate' => [
                'labour_total' => $this->estimate_labour_total,
                'parts_total' => $this->estimate_parts_total,
                'total' => $this->estimate_total,
                'currency' => $this->currency,
                'preauthorized_threshold' => $this->preauthorized_threshold,
                'approval_status' => $this->estimate_approval_status,
                'decided_at' => $this->estimate_decided_at?->toIso8601String(),
                'decided_via' => $this->estimate_decided_via,
                'expires_at' => $this->estimate_token_expires_at?->toIso8601String(),
            ],
            'lines' => RmaLineResource::collection($this->lines),
            'loaner' => $loaner !== null ? new LoanerResource($loaner) : null,
            'ddt' => [
                'causale_outbound' => $this->ddt_causale_outbound,
                'causale_return' => $this->ddt_causale_return,
                'return_delivery_note_id' => $this->return_delivery_note_id,
                'is_non_ownership_transfer' => (bool) $this->is_non_ownership_transfer,
                'vat_exempt_art15_dpr633' => (bool) $this->vat_exempt_art15_dpr633,
            ],
            'repair_outcome' => $this->repair_outcome,
            'repair_test_notes' => $this->repair_test_notes,
            'vendor_claims' => VendorClaimResource::collection($this->vendorClaims),
            'history' => $this->history
                ->map(static fn (RmaStatusHistory $entry): array => [
                    'from' => $entry->from_status,
                    'to' => $entry->to_status,
                    'actor_type' => $entry->actor_type,
                    'at' => $entry->created_at?->toIso8601String(),
                    'note' => $entry->note,
                ])
                ->values()
                ->all(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
