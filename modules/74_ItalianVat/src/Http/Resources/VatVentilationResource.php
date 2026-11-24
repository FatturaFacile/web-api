<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\VatVentilation;

/**
 * @mixin VatVentilation
 */
final class VatVentilationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date->toDateString(),
            'gross_takings' => (string) $this->gross_takings,
            'purchase_basis' => $this->purchase_basis,
            'breakdown' => $this->breakdown,
            'total_taxable' => (string) $this->total_taxable,
            'total_vat' => (string) $this->total_vat,
            'registered_at' => $this->registered_at?->toIso8601String(),
        ];
    }
}
