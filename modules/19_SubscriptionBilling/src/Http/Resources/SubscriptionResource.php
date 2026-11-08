<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

/**
 * A subscription contract; amounts are decimal strings.
 *
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'subscription_number' => $this->subscription_number,
            'name' => $this->name,
            'customer_id' => $this->customer_id,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'trial_end_date' => $this->trial_end_date?->toDateString(),
            'billing_alignment' => $this->billing_alignment,
            'current_period_start' => $this->current_period_start?->toDateString(),
            'current_period_end' => $this->current_period_end?->toDateString(),
            'next_billing_date' => $this->isCancelled() ? null : $this->current_period_start?->toDateString(),
            'term_end_date' => $this->term_end_date?->toDateString(),
            'contract_term_months' => (int) $this->contract_term_months,
            'auto_renew' => (bool) $this->auto_renew,
            'renewal_notice_days' => (int) $this->renewal_notice_days,
            'cancellation_notice_days' => (int) $this->cancellation_notice_days,
            'renewal_price_increase_percent' => $this->getRawOriginal('renewal_price_increase_percent'),
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'cancellation_type' => $this->cancellation_type,
            'cancellation_reason_code' => $this->cancellation_reason_code,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_effective_date' => $this->cancellation_effective_date?->toDateString(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'resume_on' => $this->resume_on?->toDateString(),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'payment_method' => $this->payment_method,
            'currency' => $this->currency,
            'mrr' => (string) $this->getRawOriginal('mrr'),
            'arr' => (string) $this->getRawOriginal('arr'),
            'is_locked' => (bool) $this->is_locked,
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (SubscriptionItem $item): array => [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'description' => $item->description,
                'quantity' => (string) $item->getRawOriginal('quantity'),
                'unit_price' => (string) $item->getRawOriginal('unit_price'),
                'discount_percentage' => (string) $item->getRawOriginal('discount_percentage'),
                'tax_rate' => $item->getRawOriginal('tax_rate'),
                'price_factor' => (string) $item->getRawOriginal('price_factor'),
                'subtotal_recurring' => (string) $item->getRawOriginal('subtotal_recurring'),
                'metric_id' => $item->metric_id,
            ])->values()->all()),
            'versions' => $this->whenLoaded('versions', fn (): array => $this->versions->map(fn (SubscriptionVersion $version): array => [
                'version_number' => (int) $version->version_number,
                'change_type' => $version->change_type,
                'effective_date' => $version->effective_date?->toDateString(),
                'mrr_before' => (string) $version->getRawOriginal('mrr_before'),
                'mrr_after' => (string) $version->getRawOriginal('mrr_after'),
                'proration_amount' => (string) $version->getRawOriginal('proration_amount'),
                'change_reason' => $version->change_reason,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
