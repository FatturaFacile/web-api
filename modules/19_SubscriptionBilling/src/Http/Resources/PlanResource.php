<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * A subscription plan with its price tiers and features; amounts are decimal strings.
 *
 * @mixin SubscriptionPlan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
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
            'billing_alignment' => $this->billing_alignment,
            'pricing_model' => $this->pricing_model,
            'currency' => $this->currency,
            'setup_fee' => (string) $this->getRawOriginal('setup_fee'),
            'base_price' => (string) $this->getRawOriginal('base_price'),
            'tax_rate' => $this->getRawOriginal('tax_rate'),
            'trial_period_days' => (int) $this->trial_period_days,
            'renewal_price_increase_percent' => (string) $this->getRawOriginal('renewal_price_increase_percent'),
            'revenue_account_id' => $this->revenue_account_id,
            'deferred_revenue_account_id' => $this->deferred_revenue_account_id,
            'is_active' => (bool) $this->is_active,
            'tiers' => $this->whenLoaded('pricingTiers', fn (): array => $this->pricingTiers->map(fn (PricingTier $tier): array => [
                'tier_number' => (int) $tier->tier_number,
                'from_quantity' => (string) $tier->getRawOriginal('from_quantity'),
                'to_quantity' => $tier->getRawOriginal('to_quantity'),
                'unit_price' => (string) $tier->getRawOriginal('unit_price'),
                'flat_fee' => (string) $tier->getRawOriginal('flat_fee'),
            ])->values()->all()),
            'features' => $this->whenLoaded('features', fn (): array => $this->features->map(fn (PlanFeature $feature): array => [
                'feature_code' => $feature->feature_code,
                'name' => $feature->name,
                'metric_type' => $feature->metric_type,
                'included_quantity' => (string) $feature->getRawOriginal('included_quantity'),
                'overage_unit_price' => (string) $feature->getRawOriginal('overage_unit_price'),
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
