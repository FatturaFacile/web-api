<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\VatRegisterPrint;

/**
 * @mixin VatRegisterPrint
 */
final class VatRegisterPrintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'year' => $this->year,
            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'first_protocol' => $this->first_protocol,
            'last_protocol' => $this->last_protocol,
            'entries_count' => $this->entries_count,
            'page_from' => $this->page_from,
            'page_to' => $this->page_to,
            'total_taxable' => (string) $this->total_taxable,
            'total_vat' => (string) $this->total_vat,
            'total_deductible_vat' => (string) $this->total_deductible_vat,
            'content_hash' => $this->content_hash,
            'stamp_reference' => $this->stamp_reference,
            'printed_at' => $this->printed_at->toIso8601String(),
            'printed_by' => $this->printed_by,
        ];
    }
}
