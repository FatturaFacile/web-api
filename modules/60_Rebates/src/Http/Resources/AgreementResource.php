<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateAgreement;

/**
 * A rebate agreement with its tiers and, when computed, the rebate accrued and not settled yet.
 *
 * @mixin RebateAgreement
 */
final class AgreementResource extends JsonResource
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
            'direction' => $this->direction,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->whenLoaded('contact', fn (): ?string => $this->contact === null ? null : $this->contactName()),
            'tier_mode' => $this->tier_mode,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $this->notes,
            'rebate_type' => $this->rebate_type,
            'supplier_contract_id' => $this->supplier_contract_id,
            'fixed_bonus_target' => $this->fixed_bonus_target,
            'fixed_bonus_amount' => $this->fixed_bonus_amount,
            'accrual_frequency' => $this->accrual_frequency,
            'accrual_account_id' => $this->accrual_account_id,
            'income_account_id' => $this->income_account_id,
            'version' => $this->version,
            'contributions' => ContributionResource::collection($this->whenLoaded('contributions')),
            'tiers' => TierResource::collection($this->whenLoaded('tiers')),
            'open_rebate' => $this->when(array_key_exists('open_rebate_sum', $this->resource->getAttributes()), fn (): string => number_format((float) $this->resource->getAttribute('open_rebate_sum'), 2, '.', '')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function contactName(): string
    {
        $company = trim((string) $this->contact->company_name);

        return $company !== '' ? $company : trim($this->contact->first_name.' '.$this->contact->last_name);
    }
}
