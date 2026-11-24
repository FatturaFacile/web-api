<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\CuCertification;

/**
 * @mixin CuCertification
 */
final class CuCertificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'supplier_id' => $this->supplier_id,
            'recipient_name' => $this->recipient_name,
            'recipient_tax_code' => $this->recipient_tax_code,
            'recipient_vat_number' => $this->recipient_vat_number,
            'recipient_kind' => $this->recipient_kind,
            'reason_code' => $this->reason_code,
            'gross_amount' => (string) $this->gross_amount,
            'non_taxable_amount' => (string) $this->non_taxable_amount,
            'taxable_amount' => (string) $this->taxable_amount,
            'withheld_amount' => (string) $this->withheld_amount,
            'contributions_amount' => (string) $this->contributions_amount,
            'payments_count' => $this->payments_count,
            'status' => $this->status,
            'validation_errors' => $this->validation_errors,
            'issued_at' => $this->issued_at?->toIso8601String(),
        ];
    }
}
