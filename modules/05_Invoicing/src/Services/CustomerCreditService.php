<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\SalesOrderCreditCheckedEvent;
use Modules\Invoicing\Events\SalesOrderHoldPlacedEvent;
use Modules\Invoicing\Events\SalesOrderHoldReleasedEvent;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

final class CustomerCreditService implements CustomerCreditServiceInterface
{
    public function __construct(
        private readonly CustomerLedgerServiceInterface $ledgerService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function evaluateCredit(string $customerId, string $tenantId): CustomerCreditProfile
    {
        $profile = CustomerCreditProfile::firstOrCreate(
            ['tenant_id' => $tenantId, 'customer_id' => $customerId],
            [
                'id' => (string) Str::uuid7(),
                'credit_limit' => 50000.00,
                'used_credit' => 0.0,
                'pending_orders_amount' => 0.0,
                'total_exposure' => 0.0,
                'overdue_amount' => 0.0,
                'credit_status' => 'good',
                'risk_rating' => 'low',
                'is_credit_hold' => false,
            ]
        );

        // 1. Used credit = sum of open invoice balances
        $usedCredit = (float) SalesInvoice::where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw('SUM(total_gross - total_paid) as outstanding')
            ->value('outstanding') ?? 0.0;

        // 2. Pending orders = confirmed or in fulfillment orders not yet billed
        $pendingOrders = (float) SalesOrder::where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('status', ['confirmed', 'in_fulfillment', 'partially_delivered'])
            ->where('billing_status', '!=', 'billed')
            ->sum('total_gross') ?? 0.0;

        $totalExposure = round($usedCredit + $pendingOrders, 2);

        // 3. Aging analysis
        $aging = $this->ledgerService->calculateAging($customerId, $tenantId);
        $overdueAmount = $aging['total_overdue'];
        $maxOverdueDays = $aging['days_90_plus'] > 0 ? 95 : ($aging['days_61_90'] > 0 ? 75 : ($aging['days_31_60'] > 0 ? 45 : ($aging['days_1_30'] > 0 ? 15 : 0)));

        $creditLimit = (float) $profile->credit_limit;
        $isOverLimit = $creditLimit > 0 && $totalExposure > $creditLimit;
        $hasCriticalOverdue = $aging['days_61_90'] > 0 || $aging['days_90_plus'] > 0;

        $status = 'good';
        $risk = 'low';
        $hold = false;
        $holdReason = null;

        if ($hasCriticalOverdue) {
            $status = 'blocked';
            $risk = 'critical';
            $hold = true;
            $holdReason = 'Customer has severely overdue invoices exceeding 60 days.';
        } elseif ($isOverLimit) {
            $status = 'hold';
            $risk = 'high';
            $hold = true;
            $holdReason = "Exposure ({$totalExposure} EUR) exceeds credit limit ({$creditLimit} EUR).";
        } elseif ($overdueAmount > 0) {
            $status = 'warning';
            $risk = 'medium';
        }

        $profile->update([
            'used_credit' => $usedCredit,
            'pending_orders_amount' => $pendingOrders,
            'total_exposure' => $totalExposure,
            'overdue_amount' => $overdueAmount,
            'max_overdue_days' => $maxOverdueDays,
            'credit_status' => $status,
            'risk_rating' => $risk,
            'is_credit_hold' => $hold,
            'hold_reason' => $holdReason,
            'last_evaluated_at' => now(),
        ]);

        return $profile;
    }

    /**
     * @return array{approved: bool, status: string, reason: ?string, exposure: float, limit: float}
     */
    public function checkOrderCredit(SalesOrder $order): array
    {
        $customerId = (string) $order->contact_id;
        $tenantId = (string) $order->tenant_id;

        $profile = $this->evaluateCredit($customerId, $tenantId);
        $orderTotal = (float) $order->total_gross;
        $limit = (float) $profile->credit_limit;
        $projectedExposure = (float) $profile->total_exposure + $orderTotal;

        if ($profile->is_credit_hold) {
            $reason = $profile->hold_reason ?? 'Customer is currently under credit hold.';
            $order->update([
                'credit_status' => 'on_hold',
                'credit_hold_reason' => $reason,
            ]);

            $this->dispatchDomainEvent(new SalesOrderCreditCheckedEvent(
                salesOrderId: (string) $order->id,
                customerId: $customerId,
                creditStatus: 'on_hold',
                currentExposure: $projectedExposure,
                creditLimit: $limit,
                holdReason: $reason,
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            return [
                'approved' => false,
                'status' => 'on_hold',
                'reason' => $reason,
                'exposure' => $projectedExposure,
                'limit' => $limit,
            ];
        }

        if ($limit > 0 && $projectedExposure > $limit) {
            $reason = "Order total ({$orderTotal} EUR) pushes total exposure ({$projectedExposure} EUR) over credit limit ({$limit} EUR).";
            $order->update([
                'credit_status' => 'on_hold',
                'credit_hold_reason' => $reason,
            ]);

            $this->dispatchDomainEvent(new SalesOrderCreditCheckedEvent(
                salesOrderId: (string) $order->id,
                customerId: $customerId,
                creditStatus: 'on_hold',
                currentExposure: $projectedExposure,
                creditLimit: $limit,
                holdReason: $reason,
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            return [
                'approved' => false,
                'status' => 'on_hold',
                'reason' => $reason,
                'exposure' => $projectedExposure,
                'limit' => $limit,
            ];
        }

        $order->update([
            'credit_status' => 'approved',
            'credit_hold_reason' => null,
        ]);

        $this->dispatchDomainEvent(new SalesOrderCreditCheckedEvent(
            salesOrderId: (string) $order->id,
            customerId: $customerId,
            creditStatus: 'approved',
            currentExposure: $projectedExposure,
            creditLimit: $limit,
            holdReason: null,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return [
            'approved' => true,
            'status' => 'approved',
            'reason' => null,
            'exposure' => $projectedExposure,
            'limit' => $limit,
        ];
    }

    public function placeHold(SalesOrder $order, string $holdType, string $reason, string $userId): SalesOrderHold
    {
        $tenantId = (string) $order->tenant_id;

        $hold = SalesOrderHold::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'sales_order_id' => (string) $order->id,
            'hold_type' => $holdType,
            'status' => 'active',
            'hold_reason' => $reason,
            'placed_by' => Str::isUuid($userId) ? $userId : (string) Str::uuid7(),
            'placed_at' => now(),
        ]);

        $order->update([
            'status' => 'on_hold',
            'credit_status' => 'on_hold',
            'credit_hold_reason' => $reason,
        ]);

        $this->dispatchDomainEvent(new SalesOrderHoldPlacedEvent(
            holdId: (string) $hold->id,
            salesOrderId: (string) $order->id,
            holdType: $holdType,
            holdReason: $reason,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $hold;
    }

    public function releaseHold(SalesOrderHold $hold, string $userId, ?string $notes = null): SalesOrderHold
    {
        $hold->update([
            'status' => 'released',
            'released_by' => Str::isUuid($userId) ? $userId : (string) Str::uuid7(),
            'released_at' => now(),
            'release_notes' => $notes,
        ]);

        $order = $hold->salesOrder;
        if ($order !== null) {
            // Check if there are other active holds
            $remainingHolds = SalesOrderHold::where('sales_order_id', (string) $order->id)
                ->where('status', 'active')
                ->count();

            if ($remainingHolds === 0) {
                $order->update([
                    'status' => 'confirmed',
                    'credit_status' => 'approved',
                    'credit_hold_reason' => null,
                ]);
            }

            $this->dispatchDomainEvent(new SalesOrderHoldReleasedEvent(
                holdId: (string) $hold->id,
                salesOrderId: (string) $order->id,
                releasedBy: $userId,
                releaseNotes: $notes,
                tenantId: (string) $order->tenant_id,
                companyId: (string) $order->tenant_id
            ));
        }

        return $hold;
    }

    public function updateCreditLimit(string $customerId, float $newLimit, string $userId, string $tenantId): CustomerCreditProfile
    {
        $profile = CustomerCreditProfile::firstOrCreate(
            ['tenant_id' => $tenantId, 'customer_id' => $customerId],
            ['id' => (string) Str::uuid7(), 'credit_limit' => 0.0]
        );

        $oldLimit = (float) $profile->credit_limit;
        $profile->update([
            'credit_limit' => $newLimit,
            'last_evaluated_at' => now(),
            'evaluated_by' => Str::isUuid($userId) ? $userId : (string) Str::uuid7(),
        ]);

        $this->evaluateCredit($customerId, $tenantId);

        $this->dispatchDomainEvent(new CustomerCreditLimitUpdatedEvent(
            creditProfileId: (string) $profile->id,
            customerId: $customerId,
            oldLimit: $oldLimit,
            newLimit: $newLimit,
            updatedBy: $userId,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $profile;
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }
}
