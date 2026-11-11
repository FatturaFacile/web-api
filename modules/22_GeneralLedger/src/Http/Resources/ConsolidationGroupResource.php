<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;

/**
 * @mixin ConsolidationGroup
 */
final class ConsolidationGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'group_currency' => $this->group_currency,
            'translation_reserve_code' => $this->translation_reserve_code,
            'consolidation_difference_code' => $this->consolidation_difference_code,
            'equity_method_result_code' => $this->equity_method_result_code,
            'equity_method_reserve_code' => $this->equity_method_reserve_code,
            'elimination_difference_policy' => $this->elimination_difference_policy,
            'goodwill_method' => $this->goodwill_method,
            'goodwill_amortisation_years' => $this->goodwill_amortisation_years,
            'require_account_mapping' => (bool) $this->require_account_mapping,
            'synthetic_accounts' => collect(ConsolidationGroup::SyntheticDefaults)->map(fn (string $default, string $role): string => $this->resource->syntheticCode($role))->all(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'members' => $this->whenLoaded('members', fn (): array => $this->members->map(static fn (ConsolidationMember $member): array => [
                'id' => (string) $member->id,
                'member_tenant_id' => (string) $member->member_tenant_id,
                'name' => $member->memberTenant->name,
                'ownership_percentage' => (string) $member->ownership_percentage,
                'consolidation_method' => $member->consolidation_method->value,
                'is_parent' => (bool) $member->is_parent,
                'holder_tenant_id' => $member->holder_tenant_id,
                'fiscal_year_offset_months' => (int) $member->fiscal_year_offset_months,
            ])->all()),
            'relations' => IntercompanyRelationResource::collection($this->whenLoaded('relations')),
        ];
    }
}
