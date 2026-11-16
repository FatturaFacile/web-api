<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\WarrantyClaim;

/**
 * A warranty claim with the validity of the warranty frozen on the claim date.
 *
 * @mixin WarrantyClaim
 */
final class WarrantyClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_number' => $this->claim_number,
            'warranty_id' => $this->warranty_id,
            'serial_number' => $this->warranty?->serial_number,
            'lot_number' => $this->warranty?->lot_number,
            'rma_id' => $this->rma_id,
            'rma_number' => $this->rma?->rma_number,
            'claim_date' => $this->claim_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status,
            'is_valid' => $this->is_valid,
            'validity_note' => $this->validity_note,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
