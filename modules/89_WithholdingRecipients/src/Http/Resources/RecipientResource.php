<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WithholdingRecipients\Models\RecipientProfile;

/**
 * @mixin RecipientProfile
 */
final class RecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'display_name' => $this->display_name,
            'regime' => $this->regime,
            'jurisdiction' => $this->jurisdiction,
            'currency' => $this->regimeEnum()->currency(),
            'tax_code' => $this->tax_code,
            'vat_number' => $this->vat_number,
            'avs_number' => $this->avs_number,
            'birth_date' => $this->birth_date?->toDateString(),
            'country_of_residence' => $this->country_of_residence,
            'canton' => $this->canton,
            'enasarco_mandate' => $this->enasarco_mandate,
            'firr_enabled' => (bool) $this->firr_enabled,
            'is_cultural_sector' => (bool) $this->is_cultural_sector,
            'activity_type' => $this->activity_type,
            'cu_causal' => $this->cu_causal ?? $this->regimeEnum()->defaultCuCausal($this->mandateEnum()),
            'third_party_ytd_income' => $this->third_party_ytd_income,
            'third_party_income_year' => $this->third_party_income_year,
            'third_party_declared_on' => $this->third_party_declared_on?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'certificates' => CertificateResource::collection($this->whenLoaded('certificates')),
            'inl_notifications' => InlNotificationResource::collection($this->whenLoaded('inlNotifications')),
        ];
    }
}
