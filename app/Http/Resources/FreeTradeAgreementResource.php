<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FreeTradeAgreement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FreeTradeAgreement
 */
class FreeTradeAgreementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'partner_countries' => $this->partner_countries,
            'value_threshold_eur' => $this->value_threshold_eur !== null ? (float) $this->value_threshold_eur : null,
            'value_threshold_chf' => $this->value_threshold_chf !== null ? (float) $this->value_threshold_chf : null,
            'proof_types' => $this->proof_types,
            'supports_approved_exporter' => (bool) $this->supports_approved_exporter,
            'supports_rex' => (bool) $this->supports_rex,
            'declaration_templates' => $this->declaration_templates,
            'declaration_templates_no_auth' => $this->declaration_templates_no_auth,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
