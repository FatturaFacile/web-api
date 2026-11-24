<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Models\CommissionRule;

/**
 * @mixin CommissionRule
 */
final class CommissionRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_profile_id' => $this->agent_profile_id,
            'name' => $this->name,
            'product_category_id' => $this->product_category_id,
            'customer_id' => $this->customer_id,
            'zone_id' => $this->zone_id,
            'zone' => $this->whenLoaded('zone', fn (): ?array => $this->zone === null ? null : ['id' => $this->zone->id, 'code' => $this->zone->code, 'name' => $this->zone->name]),
            'rate_type' => $this->rate_type,
            'rate' => (string) $this->rate,
            'fixed_amount' => $this->fixed_amount === null ? null : (string) $this->fixed_amount,
            'accrual_basis' => $this->accrual_basis,
            'priority' => (int) $this->priority,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'tiers' => $this->whenLoaded('tiers', fn (): array => $this->tiers->map(static fn ($tier): array => [
                'threshold_from' => (string) $tier->threshold_from,
                'threshold_to' => $tier->threshold_to === null ? null : (string) $tier->threshold_to,
                'rate' => (string) $tier->rate,
            ])->all()),
        ];
    }
}
