<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\CrossBorderTransferRule;

/**
 * @mixin CrossBorderTransferRule
 */
final class TransferRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_statutory_baseline' => $this->tenant_id === null,
            'source_jurisdiction' => $this->source_jurisdiction,
            'destination_country_iso2' => $this->destination_country_iso2,
            'destination_country_name' => $this->destination_country_name,
            'has_ch_adequacy' => $this->has_ch_adequacy,
            'has_eu_adequacy' => $this->has_eu_adequacy,
            'decision_status' => $this->decision_status->value,
            'legal_basis_reference' => $this->legal_basis_reference,
        ];
    }
}
