<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'subscription_number' => $this->subscription_number,
            'customer_id' => $this->customer_id,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'current_period_start' => $this->current_period_start?->toDateString(),
            'current_period_end' => $this->current_period_end?->toDateString(),
            'renewal_type' => $this->renewal_type,
            'contract_term_months' => (int) $this->contract_term_months,
            'auto_renew' => (bool) $this->auto_renew,
            'currency' => $this->currency,
            'mrr' => (float) $this->mrr,
            'arr' => (float) $this->arr,
            'is_locked' => (bool) $this->is_locked,
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
