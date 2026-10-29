<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Events\ARRUpdatedEvent;
use Modules\SubscriptionBilling\Events\MRRUpdatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionActivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCancelledEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCreatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionDowngradedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionReactivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionSuspendedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionUpgradedEvent;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

final readonly class SubscriptionLifecycleService implements SubscriptionLifecycleServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createSubscription(CreateSubscriptionDTO $dto, ?string $actorUserId = null): Subscription
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $plan = SubscriptionPlan::where('tenant_id', $dto->tenantId)
                ->where('id', $dto->planId)
                ->firstOrFail();

            $startDate = Carbon::parse($dto->startDate);
            $billingInterval = $plan->billing_interval;
            $currentPeriodEnd = match ($billingInterval) {
                'annual' => $startDate->copy()->addYear()->subDay(),
                'quarterly' => $startDate->copy()->addMonths(3)->subDay(),
                'semiannual' => $startDate->copy()->addMonths(6)->subDay(),
                default => $startDate->copy()->addMonth()->subDay(),
            };

            $basePrice = (float) $plan->base_price;
            $mrr = $this->calculateMrrFromPlan($plan, $basePrice);
            $arr = $mrr * 12.0;

            $subNumber = 'SUB-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $subscription = Subscription::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'subscription_number' => $subNumber,
                'customer_id' => $dto->customerId,
                'plan_id' => $plan->id,
                'status' => 'active',
                'start_date' => $startDate->toDateString(),
                'current_period_start' => $startDate->toDateString(),
                'current_period_end' => $currentPeriodEnd->toDateString(),
                'renewal_type' => $dto->autoRenew ? 'evergreen' : 'manual',
                'contract_term_months' => $dto->contractTermMonths,
                'auto_renew' => $dto->autoRenew,
                'billing_day_of_month' => $dto->billingDayOfMonth,
                'payment_terms_days' => $dto->paymentTermsDays,
                'payment_method' => $dto->paymentMethod,
                'bank_account_id' => $dto->bankAccountId,
                'currency' => $plan->currency,
                'mrr' => $mrr,
                'arr' => $arr,
                'is_locked' => false,
                'metadata' => $dto->metadata,
            ]);

            SubscriptionItem::create([
                'tenant_id' => $dto->tenantId,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'product_id' => $plan->product_id,
                'item_type' => 'plan',
                'description' => $plan->name,
                'quantity' => 1.0000,
                'unit_price' => $basePrice,
                'discount_percentage' => 0.00,
                'subtotal_recurring' => $basePrice,
            ]);

            foreach ($dto->items as $addon) {
                $itemPrice = (float) ($addon['unit_price'] ?? 0.00);
                $itemQty = (float) ($addon['quantity'] ?? 1.0);
                SubscriptionItem::create([
                    'tenant_id' => $dto->tenantId,
                    'subscription_id' => $subscription->id,
                    'item_type' => $addon['item_type'] ?? 'addon',
                    'description' => $addon['description'] ?? 'Addon item',
                    'quantity' => $itemQty,
                    'unit_price' => $itemPrice,
                    'discount_percentage' => (float) ($addon['discount_percentage'] ?? 0.00),
                    'subtotal_recurring' => round($itemPrice * $itemQty, 2),
                ]);
            }

            SubscriptionVersion::create([
                'tenant_id' => $dto->tenantId,
                'subscription_id' => $subscription->id,
                'version_number' => 1,
                'effective_date' => $startDate->toDateString(),
                'change_type' => 'initial',
                'mrr_before' => 0.00,
                'mrr_after' => $mrr,
                'mrr_delta' => $mrr,
                'proration_amount' => 0.00,
                'snapshot_payload' => $subscription->toArray(),
                'change_reason' => 'Initial subscription creation',
                'actor_user_id' => $actorUserId,
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_CREATED',
                $subscription,
                null,
                ['mrr' => $mrr, 'arr' => $arr, 'plan_id' => $plan->id],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new SubscriptionCreatedEvent(
                subscriptionId: $subscription->id,
                subscriptionNumber: $subscription->subscription_number,
                customerId: $subscription->customer_id,
                planId: $subscription->plan_id,
                mrr: (float) $subscription->mrr,
                arr: (float) $subscription->arr,
                currency: $subscription->currency,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            $this->dispatcher->dispatch(new MRRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldMrr: 0.00,
                newMrr: $mrr,
                mrrDelta: $mrr,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            $this->dispatcher->dispatch(new ARRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldArr: 0.00,
                newArr: $arr,
                arrDelta: $arr,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    public function activateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::where('id', $subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $subscription->status;
            $subscription->update([
                'status' => 'active',
                'is_locked' => false,
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_ACTIVATED',
                $subscription,
                ['status' => $oldStatus],
                ['status' => 'active'],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new SubscriptionActivatedEvent(
                subscriptionId: $subscription->id,
                subscriptionNumber: $subscription->subscription_number,
                customerId: $subscription->customer_id,
                mrr: (float) $subscription->mrr,
                startDate: $subscription->start_date ? $subscription->start_date->toDateString() : now()->toDateString(),
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    public function suspendSubscription(string $subscriptionId, string $reason, ?string $actorUserId = null): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $reason) {
            $subscription = Subscription::where('id', $subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $subscription->status;
            $subscription->update([
                'status' => 'paused',
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_SUSPENDED',
                $subscription,
                ['status' => $oldStatus],
                ['status' => 'paused', 'reason' => $reason],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new SubscriptionSuspendedEvent(
                subscriptionId: $subscription->id,
                subscriptionNumber: $subscription->subscription_number,
                reason: $reason,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    public function reactivateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::where('id', $subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $subscription->status;
            $subscription->update([
                'status' => 'active',
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_REACTIVATED',
                $subscription,
                ['status' => $oldStatus],
                ['status' => 'active'],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new SubscriptionReactivatedEvent(
                subscriptionId: $subscription->id,
                subscriptionNumber: $subscription->subscription_number,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    public function upgradeSubscription(UpgradeSubscriptionDTO $dto): Subscription
    {
        return $this->changePlan($dto->subscriptionId, $dto->newPlanId, 'upgrade', $dto->effectiveDate, $dto->changeReason, $dto->actorUserId);
    }

    public function downgradeSubscription(DowngradeSubscriptionDTO $dto): Subscription
    {
        return $this->changePlan($dto->subscriptionId, $dto->newPlanId, 'downgrade', $dto->effectiveDate, $dto->changeReason, $dto->actorUserId);
    }

    public function cancelSubscription(CancelSubscriptionDTO $dto): Subscription
    {
        return DB::transaction(function () use ($dto) {
            $subscription = Subscription::where('id', $dto->subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $subscription->status;
            $oldMrr = (float) $subscription->mrr;
            $oldArr = (float) $subscription->arr;
            $cancellationDate = $dto->cancellationDate ? Carbon::parse($dto->cancellationDate) : now();

            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => $cancellationDate,
                'cancellation_reason' => $dto->reason,
                'mrr' => 0.00,
                'arr' => 0.00,
            ]);

            $latestVersion = (int) $subscription->versions()->max('version_number');

            SubscriptionVersion::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'version_number' => $latestVersion + 1,
                'effective_date' => $cancellationDate->toDateString(),
                'change_type' => 'cancellation',
                'mrr_before' => $oldMrr,
                'mrr_after' => 0.00,
                'mrr_delta' => -$oldMrr,
                'proration_amount' => 0.00,
                'snapshot_payload' => $subscription->toArray(),
                'change_reason' => $dto->reason,
                'actor_user_id' => $dto->actorUserId,
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_CANCELLED',
                $subscription,
                ['status' => $oldStatus, 'mrr' => $oldMrr],
                ['status' => 'cancelled', 'reason' => $dto->reason],
                'MODULE_19_SUB'
            );

            $this->dispatcher->dispatch(new SubscriptionCancelledEvent(
                subscriptionId: $subscription->id,
                subscriptionNumber: $subscription->subscription_number,
                reason: $dto->reason,
                cancelledAt: $cancellationDate->toIso8601String(),
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            $this->dispatcher->dispatch(new MRRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldMrr: $oldMrr,
                newMrr: 0.00,
                mrrDelta: -$oldMrr,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            $this->dispatcher->dispatch(new ARRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldArr: $oldArr,
                newArr: 0.00,
                arrDelta: -$oldArr,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan, Carbon $effectiveDate): array
    {
        $periodStart = Carbon::parse($subscription->current_period_start);
        $periodEnd = Carbon::parse($subscription->current_period_end);

        $totalDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $remainingDays = max(0, $effectiveDate->diffInDays($periodEnd) + 1);

        $fractionRemaining = min(1.0, max(0.0, $remainingDays / $totalDays));

        $oldBasePrice = (float) ($subscription->plan?->base_price ?? 0.00);
        $newBasePrice = (float) $newPlan->base_price;

        $unusedCredit = round($oldBasePrice * $fractionRemaining, 2);
        $newCharge = round($newBasePrice * $fractionRemaining, 2);
        $netProration = round($newCharge - $unusedCredit, 2);

        return [
            'total_period_days' => $totalDays,
            'remaining_days' => $remainingDays,
            'fraction_remaining' => round($fractionRemaining, 4),
            'unused_credit' => $unusedCredit,
            'new_charge' => $newCharge,
            'net_proration' => $netProration,
        ];
    }

    private function changePlan(string $subscriptionId, string $newPlanId, string $changeType, ?string $effectiveDateStr, ?string $reason, ?string $actorUserId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $newPlanId, $changeType, $effectiveDateStr, $reason, $actorUserId) {
            $subscription = Subscription::with('plan')->where('id', $subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $newPlan = SubscriptionPlan::where('tenant_id', $subscription->tenant_id)
                ->where('id', $newPlanId)
                ->firstOrFail();

            $effectiveDate = $effectiveDateStr ? Carbon::parse($effectiveDateStr) : now();
            $proration = $this->calculateProration($subscription, $newPlan, $effectiveDate);

            $oldPlanId = $subscription->plan_id;
            $oldMrr = (float) $subscription->mrr;
            $oldArr = (float) $subscription->arr;

            $newMrr = $this->calculateMrrFromPlan($newPlan, (float) $newPlan->base_price);
            $newArr = $newMrr * 12.0;
            $mrrDelta = round($newMrr - $oldMrr, 2);

            $subscription->update([
                'plan_id' => $newPlan->id,
                'mrr' => $newMrr,
                'arr' => $newArr,
            ]);

            // Update plan item
            SubscriptionItem::where('subscription_id', $subscription->id)
                ->where('item_type', 'plan')
                ->update([
                    'plan_id' => $newPlan->id,
                    'product_id' => $newPlan->product_id,
                    'description' => $newPlan->name,
                    'unit_price' => $newPlan->base_price,
                    'subtotal_recurring' => $newPlan->base_price,
                ]);

            $latestVersion = (int) $subscription->versions()->max('version_number');

            SubscriptionVersion::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'version_number' => $latestVersion + 1,
                'effective_date' => $effectiveDate->toDateString(),
                'change_type' => $changeType,
                'mrr_before' => $oldMrr,
                'mrr_after' => $newMrr,
                'mrr_delta' => $mrrDelta,
                'proration_amount' => $proration['net_proration'],
                'snapshot_payload' => $subscription->toArray(),
                'change_reason' => $reason ?? "Plan {$changeType} to {$newPlan->name}",
                'actor_user_id' => $actorUserId,
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_PLAN_'.strtoupper($changeType),
                $subscription,
                ['plan_id' => $oldPlanId, 'mrr' => $oldMrr],
                ['plan_id' => $newPlan->id, 'mrr' => $newMrr, 'proration' => $proration['net_proration']],
                'MODULE_19_SUB'
            );

            if ($changeType === 'upgrade') {
                $this->dispatcher->dispatch(new SubscriptionUpgradedEvent(
                    subscriptionId: $subscription->id,
                    oldPlanId: $oldPlanId,
                    newPlanId: $newPlan->id,
                    mrrDelta: $mrrDelta,
                    prorationAmount: $proration['net_proration'],
                    tenantId: $subscription->tenant_id,
                    companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
                ));
            } else {
                $this->dispatcher->dispatch(new SubscriptionDowngradedEvent(
                    subscriptionId: $subscription->id,
                    oldPlanId: $oldPlanId,
                    newPlanId: $newPlan->id,
                    mrrDelta: $mrrDelta,
                    prorationAmount: $proration['net_proration'],
                    tenantId: $subscription->tenant_id,
                    companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
                ));
            }

            $this->dispatcher->dispatch(new MRRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldMrr: $oldMrr,
                newMrr: $newMrr,
                mrrDelta: $mrrDelta,
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            $this->dispatcher->dispatch(new ARRUpdatedEvent(
                subscriptionId: $subscription->id,
                oldArr: $oldArr,
                newArr: $newArr,
                arrDelta: round($newArr - $oldArr, 2),
                tenantId: $subscription->tenant_id,
                companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
            ));

            return $subscription;
        });
    }

    private function calculateMrrFromPlan(SubscriptionPlan $plan, float $basePrice): float
    {
        return match ($plan->billing_interval) {
            'annual' => round($basePrice / 12.0, 2),
            'quarterly' => round($basePrice / 3.0, 2),
            'semiannual' => round($basePrice / 6.0, 2),
            default => round($basePrice, 2),
        };
    }
}
