<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\CreditManagement\Models\CreditInsurancePolicy;

/**
 * A credit insurance policy.
 *
 * @mixin CreditInsurancePolicy
 */
final class InsurancePolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'insurer' => $this->insurer,
            'policy_number' => $this->policy_number,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'currency' => $this->currency,
            'coverage_percentage' => $this->coverage_percentage,
            'max_total_cover' => $this->max_total_cover,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'granted_total' => number_format((float) ($this->granted_total ?? 0), 2, '.', ''),
            'is_in_force' => $this->isInForceOn(Carbon::today()),
        ];
    }
}
