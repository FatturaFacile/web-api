<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\VatRegisterEntry;

/**
 * @mixin VatRegisterEntry
 */
final class VatRegisterEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'register_type' => $this->register_type,
            'year' => $this->year,
            'protocol_number' => $this->protocol_number,
            'entry_date' => $this->entry_date->toDateString(),
            'document_date' => $this->document_date?->toDateString(),
            'document_number' => $this->document_number,
            'document_kind' => $this->document_kind,
            'document_type_code' => $this->document_type_code,
            'contact_id' => $this->contact_id,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_vat' => $this->counterparty_vat,
            'taxable_amount' => (string) $this->taxable_amount,
            'vat_rate' => (string) $this->vat_rate,
            'vat_amount' => (string) $this->vat_amount,
            'vat_nature' => $this->vat_nature,
            'gross_amount' => (string) $this->gross_amount,
            'deduction_percentage' => (string) $this->deduction_percentage,
            'deductible_vat' => (string) $this->deductible_vat,
            'non_deductible_vat' => (string) $this->non_deductible_vat,
            'non_deductible_reason' => $this->non_deductible_reason,
            'print_id' => $this->print_id,
            'notes' => $this->notes,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'is_locked' => $this->isLocked(),
        ];
    }
}
