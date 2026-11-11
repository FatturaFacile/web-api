<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;

/**
 * @mixin IntercompanyRelation
 */
final class IntercompanyRelationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'entity_a_tenant_id' => (string) $this->entity_a_tenant_id,
            'entity_b_tenant_id' => (string) $this->entity_b_tenant_id,
            'tolerance_amount' => (string) $this->tolerance_amount,
            'description' => $this->description,
            'mappings' => $this->whenLoaded('mappings', fn (): array => $this->mappings->map(static fn (IntercompanyAccountMapping $mapping): array => [
                'id' => (string) $mapping->id,
                'mapping_type' => $mapping->mapping_type->value,
                'account_a_id' => (string) $mapping->account_a_id,
                'account_b_id' => $mapping->account_b_id === null ? null : (string) $mapping->account_b_id,
            ])->all()),
        ];
    }
}
