<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Events\UsageBillingReadyEvent;
use Modules\SubscriptionBilling\Events\UsageRatedEvent;
use Modules\SubscriptionBilling\Events\UsageRecordedEvent;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Models\UsageEvent;
use Modules\SubscriptionBilling\Models\UsageMetric;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Metered usage: idempotent usage records on the metered items of live subscriptions, aggregated and rated per
 * billing period. The rated amount is billed in arrears by the next invoice of the subscription; an aggregation that
 * is already billed is never changed again.
 */
final readonly class UsageBillingService implements UsageBillingServiceInterface
{
    public function __construct(
        private PricingCalculator $pricing,
        private SubscriptionHistory $history,
    ) {}

    /**
     * Records a usage event; the same idempotency key returns the event already recorded.
     *
     * @throws ValidationException when the item is not a metered item of a live subscription or the metric differs
     */
    public function recordUsage(RecordUsageDTO $dto): UsageEvent
    {
        $idempotencyKey = $dto->idempotencyKey ?? (string) Str::uuid7();

        $existing = UsageEvent::query()->where('tenant_id', $dto->tenantId)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $item = SubscriptionItem::query()->with(['metric', 'subscription'])->where('tenant_id', $dto->tenantId)->whereKey($dto->subscriptionItemId)->first();

        if ($item === null || ($item->metric_id === null && $item->item_type !== 'usage_meter')) {
            throw ValidationException::withMessages(['subscription_item_id' => __('The item is not a metered item of a subscription of this company.')]);
        }

        $subscription = $item->subscription;

        if ($subscription === null || in_array($subscription->status, Subscription::CLOSED_STATUSES, true)) {
            throw ValidationException::withMessages(['subscription_item_id' => __('The subscription of the item is closed.')]);
        }

        if ($item->metric !== null && $item->metric->metric_code !== $dto->metricCode) {
            throw ValidationException::withMessages(['metric_code' => __('The metric does not match the metric of the item.')]);
        }

        $timestamp = $dto->eventTimestamp !== null ? CarbonImmutable::parse($dto->eventTimestamp) : CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($dto, $item, $idempotencyKey, $timestamp): UsageEvent {
                $event = UsageEvent::create([
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'subscription_id' => $item->subscription_id,
                    'subscription_item_id' => $item->id,
                    'metric_code' => $dto->metricCode,
                    'idempotency_key' => $idempotencyKey,
                    'quantity' => Money::round4($dto->quantity),
                    'event_timestamp' => $timestamp,
                    'is_processed' => false,
                    'metadata' => $dto->metadata,
                ]);

                $this->history->publish(new UsageRecordedEvent(
                    usageEventId: (string) $event->id,
                    subscriptionId: (string) $event->subscription_id,
                    metricCode: (string) $event->metric_code,
                    quantity: (float) Money::round4($dto->quantity),
                    tenantId: (string) $event->tenant_id,
                    companyId: (string) ($event->company_id ?? $event->tenant_id)
                ));

                return $event;
            });
        } catch (QueryException $e) {
            // Two requests with the same key: the unique index lets one in, the other returns it.
            return UsageEvent::query()->where('tenant_id', $dto->tenantId)->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }

    /**
     * Aggregates and rates the usage of a metered item in a period. A billed aggregation is returned unchanged.
     */
    public function aggregateUsage(string $subscriptionItemId, CarbonInterface $periodStart, CarbonInterface $periodEnd): UsageAggregation
    {
        return DB::transaction(function () use ($subscriptionItemId, $periodStart, $periodEnd): UsageAggregation {
            $item = SubscriptionItem::query()->with(['subscription.plan.features', 'subscription.plan.pricingTiers', 'metric'])->whereKey($subscriptionItemId)->lockForUpdate()->firstOrFail();
            $start = CarbonImmutable::instance($periodStart)->startOfDay();
            $end = CarbonImmutable::instance($periodEnd)->startOfDay();

            $existing = UsageAggregation::query()
                ->where('subscription_item_id', $item->id)
                ->whereDate('period_start', $start->toDateString())
                ->whereDate('period_end', $end->toDateString())
                ->first();

            if ($existing !== null && $existing->is_billed) {
                return $existing;
            }

            $events = UsageEvent::query()
                ->where('subscription_item_id', $item->id)
                ->where('event_timestamp', '>=', $start)
                ->where('event_timestamp', '<', $end->addDay())
                ->orderBy('event_timestamp')
                ->get();

            $quantities = $events->map(fn (UsageEvent $event): string => (string) $event->getRawOriginal('quantity'));
            $consumed = match ($item->metric_id !== null ? (string) UsageMetric::query()->whereKey($item->metric_id)->value('aggregation_type') : 'sum') {
                'max' => $quantities->reduce(fn (?string $carry, string $quantity): string => $carry === null ? $quantity : Money::max($carry, $quantity)) ?? '0',
                'count' => (string) $events->count(),
                'last' => $quantities->last() ?? '0',
                default => Money::sum($quantities),
            };

            $plan = $item->subscription?->plan;
            $metricCode = $item->metric?->metric_code;
            $feature = $plan?->features->first(fn (PlanFeature $feature): bool => $metricCode !== null && $feature->feature_code === $metricCode);
            $rating = $plan !== null
                ? $this->pricing->rateUsage($plan, $feature, $consumed, (string) $item->getRawOriginal('unit_price'))
                : ['included' => '0', 'billable' => Money::round4($consumed), 'unit_price' => Money::round4((string) $item->getRawOriginal('unit_price')), 'amount' => Money::round2(Money::mul($consumed, (string) $item->getRawOriginal('unit_price')))];

            $aggregation = $existing ?? new UsageAggregation([
                'tenant_id' => $item->tenant_id,
                'subscription_item_id' => $item->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);
            $aggregation->fill([
                'total_consumed' => Money::round4($consumed),
                'included_allowance' => $rating['included'],
                'billable_overage' => $rating['billable'],
                'unit_price' => $rating['unit_price'],
                'billable_amount' => $rating['amount'],
                'is_billed' => false,
            ])->save();

            UsageEvent::query()->whereIn('id', $events->pluck('id'))->update(['is_processed' => true, 'processed_at' => now()]);

            $owner = Subscription::query()->whereKey($item->subscription_id)->firstOrFail(['company_id', 'currency']);
            $companyId = (string) ($owner->company_id ?? $item->tenant_id);
            $this->history->publish(new UsageRatedEvent(
                aggregationId: (string) $aggregation->id,
                subscriptionItemId: (string) $item->id,
                billableOverage: (float) $rating['billable'],
                billableAmount: (float) $rating['amount'],
                tenantId: (string) $item->tenant_id,
                companyId: $companyId
            ));

            if (Money::isPositive($rating['amount'])) {
                $this->history->publish(new UsageBillingReadyEvent(
                    aggregationId: (string) $aggregation->id,
                    subscriptionId: (string) $item->subscription_id,
                    billableAmount: (float) $rating['amount'],
                    currency: (string) ($owner->currency ?? 'EUR'),
                    tenantId: (string) $item->tenant_id,
                    companyId: $companyId
                ));
            }

            return $aggregation;
        });
    }

    /**
     * Rated amount of an aggregation, as a decimal string.
     */
    public function rateUsage(UsageAggregation $aggregation): string
    {
        return Money::round2((string) $aggregation->getRawOriginal('billable_amount'));
    }
}
