<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'plan_code' => $this->plan_code,
            'name' => $this->name,
            'description' => $this->description,
            'billing_interval' => $this->billing_interval,
            'interval_count' => (int) $this->interval_count,
            'pricing_model' => $this->pricing_model,
            'currency' => $this->currency,
            'setup_fee' => (float) $this->setup_fee,
            'base_price' => (float) $this->base_price,
            'trial_period_days' => (int) $this->trial_period_days,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
