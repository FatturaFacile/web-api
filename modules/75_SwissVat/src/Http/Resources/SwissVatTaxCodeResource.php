<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatTaxCode;

/**
 * @mixin SwissVatTaxCode
 */
final class SwissVatTaxCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'is_global' => $this->tenant_id === null,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'rate_type' => $this->rate_type,
            'exemption_type' => $this->exemption_type,
            'rate_percent' => (string) $this->rate_percent,
            'afc_box' => $this->afc_box,
            'is_bezugsteuer' => (bool) $this->is_bezugsteuer,
            'is_input_tax_deductible' => (bool) $this->is_input_tax_deductible,
            'sss_sector_code' => $this->sss_sector_code,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
