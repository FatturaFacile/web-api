<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;

/**
 * Storage paths never leave the API: the client learns only whether a document is on file.
 *
 * @mixin FiscalRepresentativeMandate
 */
final class MandateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'representative_name' => $this->representative_name,
            'representative_tax_id' => $this->representative_tax_id,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'email' => $this->email,
            'phone' => $this->phone,
            'mandate_start_date' => $this->mandate_start_date->format('Y-m-d'),
            'mandate_end_date' => $this->mandate_end_date?->format('Y-m-d'),
            'has_power_of_attorney' => $this->power_of_attorney_document_path !== null && $this->power_of_attorney_document_path !== '',
            'has_certificate' => $this->certificate_of_taxable_status_path !== null && $this->certificate_of_taxable_status_path !== '',
            'certificate_expiry_date' => $this->certificate_expiry_date?->format('Y-m-d'),
            'certificate_valid' => $this->hasValidCertificate(now()),
            'is_active' => $this->is_active,
        ];
    }
}
