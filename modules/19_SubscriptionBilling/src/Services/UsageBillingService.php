<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Events\UsageBillingReadyEvent;
use Modules\SubscriptionBilling\Events\UsageRatedEvent;
use Modules\SubscriptionBilling\Events\UsageRecordedEvent;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Models\UsageEvent;

final readonly class UsageBillingService implements UsageBillingServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function recordUsage(RecordUsageDTO $dto): UsageEvent
    {
        return DB::transaction(function () use ($dto) {
            $idempotencyKey = $dto->idempotencyKey ?? (string) Str::uuid7();

            $existing = UsageEvent::where('tenant_id', $dto->tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $eventTimestamp = $dto->eventTimestamp ? Carbon::parse($dto->eventTimestamp) : now();

            $event = UsageEvent::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'subscription_id' => $dto->subscriptionId,
                'subscription_item_id' => $dto->subscriptionItemId,
                'metric_code' => $dto->metricCode,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $dto->quantity,
                'event_timestamp' => $eventTimestamp,
                'is_processed' => false,
                'metadata' => $dto->metadata,
            ]);

            $this->auditLedger->record(
                'USAGE_RECORDED',
                $event,
                null,
                ['metric' => $dto->metricCode, 'quantity' => $dto->quantity],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new UsageRecordedEvent(
                usageEventId: $event->id,
                subscriptionId: $event->subscription_id,
                metricCode: $event->metric_code,
                quantity: (float) $event->quantity,
                tenantId: $event->tenant_id,
                companyId: (string) ($event->company_id ?? $event->tenant_id)
            ));

            return $event;
        });
    }

    public function aggregateUsage(string $subscriptionItemId, Carbon $periodStart, Carbon $periodEnd): UsageAggregation
    {
        return DB::transaction(function () use ($subscriptionItemId, $periodStart, $periodEnd) {
            $item = SubscriptionItem::with(['subscription.plan.features', 'metric'])
                ->where('id', $subscriptionItemId)
                ->firstOrFail();

            $events = UsageEvent::where('subscription_item_id', $subscriptionItemId)
                ->whereBetween('event_timestamp', [
                    $periodStart->copy()->startOfDay(),
                    $periodEnd->copy()->endOfDay(),
                ])
                ->lockForUpdate()
                ->get();

            $totalConsumed = 0.0;
            $aggregationType = $item->metric?->aggregation_type ?? 'sum';

            if ($events->isNotEmpty()) {
                $quantities = $events->pluck('quantity')->map(fn ($q) => (float) $q);
                $totalConsumed = match ($aggregationType) {
                    'max' => (float) $quantities->max(),
                    'count' => (float) $events->count(),
                    'last' => (float) $quantities->last(),
                    default => (float) $quantities->sum(),
                };
            }

            // Look up plan feature allowance and overage rate
            $metricCode = $item->metric?->metric_code ?? $item->description;
            $feature = $item->subscription?->plan?->features
                ->first(fn (PlanFeature $f) => $f->feature_code === $metricCode || str_contains(strtolower($f->name), strtolower($metricCode)));

            $includedAllowance = (float) ($feature?->included_quantity ?? 0.0);
            $overageRate = (float) ($feature?->overage_unit_price ?? $item->unit_price ?? 0.0);

            $billableOverage = max(0.0, $totalConsumed - $includedAllowance);
            $billableAmount = round($billableOverage * $overageRate, 2);

            $aggregation = UsageAggregation::updateOrCreate(
                [
                    'tenant_id' => $item->tenant_id,
                    'subscription_item_id' => $item->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ],
                [
                    'total_consumed' => $totalConsumed,
                    'included_allowance' => $includedAllowance,
                    'billable_overage' => $billableOverage,
                    'unit_price' => $overageRate,
                    'billable_amount' => $billableAmount,
                    'is_billed' => false,
                ]
            );

            // Mark events as processed
            UsageEvent::whereIn('id', $events->pluck('id'))->update([
                'is_processed' => true,
                'processed_at' => now(),
            ]);

            $this->auditLedger->record(
                'USAGE_AGGREGATED',
                $aggregation,
                null,
                [
                    'total_consumed' => $totalConsumed,
                    'allowance' => $includedAllowance,
                    'overage' => $billableOverage,
                    'amount' => $billableAmount,
                ],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new UsageRatedEvent(
                aggregationId: $aggregation->id,
                subscriptionItemId: $item->id,
                billableOverage: $billableOverage,
                billableAmount: $billableAmount,
                tenantId: $item->tenant_id,
                companyId: (string) ($item->subscription?->company_id ?? $item->tenant_id)
            ));

            if ($billableAmount > 0.0) {
                $this->dispatcher->dispatch(new UsageBillingReadyEvent(
                    aggregationId: $aggregation->id,
                    subscriptionId: $item->subscription_id,
                    billableAmount: $billableAmount,
                    currency: $item->subscription?->currency ?? 'EUR',
                    tenantId: $item->tenant_id,
                    companyId: (string) ($item->subscription?->company_id ?? $item->tenant_id)
                ));
            }

            return $aggregation;
        });
    }

    public function rateUsage(UsageAggregation $aggregation): float
    {
        return (float) $aggregation->billable_amount;
    }
}
