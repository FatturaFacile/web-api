<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;

/**
 * @mixin CuAutonomousRecord
 */
final class CuRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_profile_id' => $this->recipient_profile_id,
            'recipient_name' => $this->whenLoaded('profile', fn (): ?string => $this->profile?->display_name),
            'tax_code' => $this->whenLoaded('profile', fn (): ?string => $this->profile?->tax_code),
            'tax_year' => $this->tax_year,
            'return_type' => $this->return_type,
            'causal' => $this->causal,
            'currency' => $this->currency,
            'gross_total' => $this->gross_total,
            'non_taxable_total' => $this->non_taxable_total,
            'non_taxable_code' => $this->non_taxable_code,
            'taxable_total' => $this->taxable_total,
            'withholding_total' => $this->withholding_total,
            'inps_worker_total' => $this->inps_worker_total,
            'inps_company_total' => $this->inps_company_total,
            'enasarco_total' => $this->enasarco_total,
            'avs_total' => $this->avs_total,
            'source_tax_total' => $this->source_tax_total,
            'settlement_count' => $this->settlement_count,
            'status' => $this->status,
            'generated_at' => $this->generated_at->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
