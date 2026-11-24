<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatBezugsteuerEntry;

/**
 * @mixin SwissVatBezugsteuerEntry
 */
final class BezugsteuerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'swiss_vat_return_id' => $this->swiss_vat_return_id ? (string) $this->swiss_vat_return_id : null,
            'source_document_type' => $this->source_document_type,
            'source_document_id' => $this->source_document_id ? (string) $this->source_document_id : null,
            'supplier_id' => $this->supplier_id ? (string) $this->supplier_id : null,
            'supplier_name' => $this->supplier_name,
            'supplier_country' => $this->supplier_country,
            'supplier_vat_number' => $this->supplier_vat_number,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->toDateString(),
            'service_category' => $this->service_category,
            'description' => $this->description,
            'currency' => $this->currency,
            'original_net_amount' => (string) $this->original_net_amount,
            'exchange_rate' => (string) $this->exchange_rate,
            'net_amount_chf' => (string) $this->net_amount_chf,
            'vat_rate_percent' => (string) $this->vat_rate_percent,
            'tax_amount_chf' => (string) $this->tax_amount_chf,
            'deductible_input_tax_chf' => (string) $this->deductible_input_tax_chf,
            'afc_box' => $this->afc_box,
            'detection_source' => $this->detection_source,
            'detection_reason' => $this->detection_reason,
            'status' => $this->status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
    }
}
