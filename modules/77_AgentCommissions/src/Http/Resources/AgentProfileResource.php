<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Models\AgentProfile;

/**
 * @mixin AgentProfile
 */
final class AgentProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_representative_id' => $this->sales_representative_id,
            'representative' => $this->whenLoaded('representative', fn (): ?array => $this->representative === null ? null : [
                'id' => $this->representative->id,
                'rep_code' => $this->representative->rep_code,
                'name' => $this->representative->name,
                'email' => $this->representative->email,
                'default_commission_rate' => (string) $this->representative->default_commission_rate,
            ]),
            'agent_type' => $this->agent_type,
            'fiscal_regime' => $this->fiscal_regime,
            'enasarco_mandate' => $this->enasarco_mandate,
            'parent_agent_profile_id' => $this->parent_agent_profile_id,
            'parent_name' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->representative?->name),
            'parent_split_mode' => $this->parent_split_mode,
            'parent_split_value' => $this->parent_split_value === null ? null : (string) $this->parent_split_value,
            'accrual_basis' => $this->accrual_basis,
            'default_rate' => $this->default_rate === null ? null : (string) $this->default_rate,
            'settlement_channel' => $this->settlement_channel,
            'has_own_organization' => (bool) $this->has_own_organization,
            'vat_applies' => (bool) $this->vat_applies,
            'firr_enabled' => (bool) $this->firr_enabled,
            'enasarco_registration_number' => $this->enasarco_registration_number,
            'tax_code' => $this->tax_code,
            'vat_number' => $this->vat_number,
            'contract_start' => $this->contract_start?->toDateString(),
            'contract_end' => $this->contract_end?->toDateString(),
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
