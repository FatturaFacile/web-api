<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * @mixin CustomerCreditProfile
 */
final class CustomerCreditProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'credit_limit' => (float) $this->credit_limit,
            'used_credit' => (float) $this->used_credit,
            'pending_orders_amount' => (float) $this->pending_orders_amount,
            'total_exposure' => (float) $this->total_exposure,
            'overdue_amount' => (float) $this->overdue_amount,
            'max_overdue_days' => $this->max_overdue_days,
            'credit_status' => $this->credit_status,
            'risk_rating' => $this->risk_rating,
            'is_credit_hold' => (bool) $this->is_credit_hold,
            'hold_reason' => $this->hold_reason,
            'last_evaluated_at' => $this->last_evaluated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
