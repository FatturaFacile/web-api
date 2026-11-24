<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatInputTaxAdjustment;

/**
 * @mixin SwissVatInputTaxAdjustment
 */
final class InputTaxAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'swiss_vat_return_id' => $this->swiss_vat_return_id ? (string) $this->swiss_vat_return_id : null,
            'adjustment_type' => $this->adjustment_type,
            'afc_box' => $this->afc_box,
            'effective_date' => $this->effective_date->toDateString(),
            'fiscal_year' => (int) $this->fiscal_year,
            'asset_category' => $this->asset_category,
            'description' => $this->description,
            'reference_document' => $this->reference_document,
            'original_cost_chf' => $this->original_cost_chf !== null ? (string) $this->original_cost_chf : null,
            'original_vat_rate' => $this->original_vat_rate !== null ? (string) $this->original_vat_rate : null,
            'years_elapsed' => $this->years_elapsed,
            'private_or_exempt_ratio' => $this->private_or_exempt_ratio !== null ? (string) $this->private_or_exempt_ratio : null,
            'adjustment_amount_chf' => (string) $this->adjustment_amount_chf,
            'legal_basis' => $this->legal_basis,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
