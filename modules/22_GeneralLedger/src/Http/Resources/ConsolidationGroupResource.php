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
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'members' => $this->whenLoaded('members', fn (): array => $this->members->map(static fn (ConsolidationMember $member): array => [
                'id' => (string) $member->id,
                'member_tenant_id' => (string) $member->member_tenant_id,
                'name' => $member->memberTenant->name,
                'ownership_percentage' => (string) $member->ownership_percentage,
                'consolidation_method' => $member->consolidation_method->value,
                'is_parent' => (bool) $member->is_parent,
            ])->all()),
            'relations' => IntercompanyRelationResource::collection($this->whenLoaded('relations')),
        ];
    }
}
