<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgSupplierDataRequest;

/**
 * A supplier-specific emission data request.
 *
 * @mixin EsgSupplierDataRequest
 */
final class SupplierDataRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reporting_period_id' => $this->reporting_period_id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->company_name,
            'document_requirement_id' => $this->document_requirement_id,
            'submission_id' => $this->submission_id,
            'scope3_category' => $this->scope3_category,
            'activity_type' => $this->activity_type,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'reported_co2e_kg' => $this->reported_co2e_kg,
            'calculation_method' => $this->calculation_method,
            'emission_factor_id' => $this->emission_factor_id,
            'activity_record_id' => $this->activity_record_id,
            'notes' => $this->notes,
            'received_at' => $this->received_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
