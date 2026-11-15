<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditInsuranceCover;

/**
 * The cover granted on a customer.
 *
 * @mixin CreditInsuranceCover
 */
final class InsuranceCoverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->policy_id,
            'customer_id' => $this->customer_id,
            'insured_limit' => $this->insured_limit,
            'status' => $this->status,
            'granted_on' => $this->granted_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'customer_name' => $this->customer_name ?? null,
        ];
    }
}
