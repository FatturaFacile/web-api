<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\CustomerCreditLimit;

/**
 * @mixin CustomerCreditLimit
 */
final class CustomerCreditLimitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'currency' => $this->currency,
            'granted_limit' => (string) $this->granted_limit,
            'insured_limit' => (string) $this->insured_limit,
            'insured_cover' => $this->insuredCover(),
            'insurer_name' => $this->insurer_name,
            'policy_number' => $this->policy_number,
            'coverage_percentage' => (string) $this->coverage_percentage,
            'max_overdue_days' => $this->max_overdue_days,
            'overdue_tolerance_amount' => (string) $this->overdue_tolerance_amount,
            'reaction_policy' => $this->reaction_policy,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
