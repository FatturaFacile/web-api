<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\AccountMapping;

/**
 * @mixin AccountMapping
 */
final class AccountMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => $this->tenant_id !== null ? (string) $this->tenant_id : null,
            'company_id' => $this->company_id !== null ? (string) $this->company_id : null,
            'source_account_id' => (string) $this->source_account_id,
            'source_account_code' => $this->whenLoaded('sourceAccount', fn () => $this->sourceAccount?->account_code),
            'source_account_name' => $this->whenLoaded('sourceAccount', fn () => $this->sourceAccount?->name),
            'target_account_id' => $this->target_account_id !== null ? (string) $this->target_account_id : null,
            'source_standard' => $this->source_standard,
            'target_standard' => $this->target_standard,
            'target_account_code' => $this->target_account_code,
            'target_account_name' => $this->target_account_name,
            'mapping_type' => $this->mapping_type,
            'is_bidirectional' => (bool) $this->is_bidirectional,
            'allocation_percentage' => (float) $this->allocation_percentage,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
