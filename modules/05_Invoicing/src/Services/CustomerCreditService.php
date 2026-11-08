<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Tenant;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\SalesOrderCreditCheckedEvent;
use Modules\Invoicing\Events\SalesOrderHoldPlacedEvent;
use Modules\Invoicing\Events\SalesOrderHoldReleasedEvent;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

/**
 * Credit management of the customers (SAP SD credit management): the exposure of a customer is what it owes on issued
 * invoices (open receivables) plus the gross value of its committed orders not yet billed. Confirming an order checks
 * the exposure the order would bring against the credit limit of the customer.
 *
 * What the check does is configured per company in the `sales` preferences:
 * - `credit_check_mode`: `block` (default) puts an order that goes over the limit on credit hold, `warn` confirms it and
 *   marks its credit status `warning`, `off` does not check;
 * - `credit_block_overdue_days`: when set, a customer with an invoice overdue for more than that many days is blocked
 *   whatever its limit. No threshold is assumed when it is not set.
 *
 * A customer without a credit profile, or with a limit of 0, has no limit: only the overdue rule (when configured) can
 * block it. Amounts are decimal strings, rounded to the cent.
 */
final class CustomerCreditService implements CustomerCreditServiceInterface
{
    public const string ModeBlock = 'block';

    public const string ModeWarn = 'warn';

    public const string ModeOff = 'off';

    /**
     * @var list<string>
     */
    public const array MODES = [self::ModeBlock, self::ModeWarn, self::ModeOff];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?TenantClock $clock = null
    ) {}

    /**
     * The credit check mode of the company: block, warn or off.
     */
    public function modeFor(string $tenantId): string
    {
        $mode = $this->preferences($tenantId)['credit_check_mode'] ?? self::ModeBlock;

        return in_array($mode, self::MODES, true) ? $mode : self::ModeBlock;
    }

    /**
     * Recomputes and stores the exposure of the customer. `$excludeOrderId` leaves one order out of the committed
     * orders, so an order being checked again is not counted twice.
     */
    public function evaluateCredit(string $customerId, string $tenantId, ?string $excludeOrderId = null): CustomerCreditProfile
    {
        return DB::transaction(function () use ($customerId, $tenantId, $excludeOrderId): CustomerCreditProfile {
            $profile = CustomerCreditProfile::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'customer_id' => $customerId],
                ['id' => (string) Str::uuid7(), 'credit_limit' => '0', 'credit_status' => 'good', 'risk_rating' => 'low', 'is_credit_hold' => false]
            );
            $profile = CustomerCreditProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $usedCredit = $this->openReceivables($customerId, $tenantId);
            $pendingOrders = $this->committedOrders($customerId, $tenantId, $excludeOrderId);
            $totalExposure = Decimal::round2(Decimal::add($usedCredit, $pendingOrders));

            [$overdueAmount, $overdueCount, $maxOverdueDays] = $this->overdue($customerId, $tenantId);
            $blockAfterDays = $this->preferences($tenantId)['credit_block_overdue_days'] ?? null;
            $limit = DocumentTotalsCalculator::decimal($profile->credit_limit);
            $isOverLimit = Decimal::isPositive($limit) && Decimal::compare($totalExposure, $limit) > 0;
            $isBlockedByOverdue = is_numeric($blockAfterDays) && $maxOverdueDays > (int) $blockAfterDays;

            [$status, $risk, $hold, $holdReason] = match (true) {
                $isBlockedByOverdue => ['blocked', 'critical', true, __('sales.credit_blocked_overdue', ['days' => $maxOverdueDays, 'limit' => (int) $blockAfterDays])],
                $isOverLimit => ['hold', 'high', true, __('sales.credit_exposure_over_limit', ['exposure' => $totalExposure, 'limit' => $limit])],
                Decimal::isPositive($overdueAmount) => ['warning', 'medium', false, null],
                default => ['good', 'low', false, null],
            };

            $profile->update([
                'used_credit' => $usedCredit,
                'pending_orders_amount' => $pendingOrders,
                'total_exposure' => $totalExposure,
                'overdue_amount' => $overdueAmount,
                'overdue_invoices_count' => $overdueCount,
                'max_overdue_days' => $maxOverdueDays,
                'credit_status' => $status,
                'risk_rating' => $risk,
                'is_credit_hold' => $hold,
                'hold_reason' => $holdReason,
                'last_evaluated_at' => now(),
            ]);

            return $profile;
        });
    }

    /**
     * Credit check of an order before it is confirmed. In `off` mode the order passes unchecked (credit status
     * `not_checked`), in `warn` mode it passes with credit status `warning` when it would be held, in `block` mode it
     * does not pass. The order row gets the outcome; the caller decides what to do with an order that did not pass.
     *
     * @return array{approved: bool, status: string, reason: ?string, exposure: string, limit: string}
     */
    public function checkOrderCredit(SalesOrder $order): array
    {
        $customerId = (string) $order->contact_id;
        $tenantId = (string) $order->tenant_id;
        $mode = $this->modeFor($tenantId);

        if ($mode === self::ModeOff) {
            $order->update(['credit_status' => 'not_checked', 'credit_hold_reason' => null]);

            return ['approved' => true, 'status' => 'not_checked', 'reason' => null, 'exposure' => '0.00', 'limit' => '0.00'];
        }

        $profile = $this->evaluateCredit($customerId, $tenantId, (string) $order->id);
        $orderTotal = DocumentTotalsCalculator::decimal($order->total_gross);
        $limit = DocumentTotalsCalculator::decimal($profile->credit_limit);
        $projectedExposure = Decimal::round2(Decimal::add(DocumentTotalsCalculator::decimal($profile->total_exposure), $orderTotal));

        $reason = match (true) {
            (bool) $profile->is_credit_hold => (string) ($profile->hold_reason ?? __('sales.credit_customer_on_hold')),
            Decimal::isPositive($limit) && Decimal::compare($projectedExposure, $limit) > 0 => __('sales.credit_order_over_limit', ['total' => $orderTotal, 'exposure' => $projectedExposure, 'limit' => $limit]),
            default => null,
        };

        $status = match (true) {
            $reason === null => 'approved',
            $mode === self::ModeWarn => 'warning',
            default => 'on_hold',
        };

        $order->update(['credit_status' => $status, 'credit_hold_reason' => $reason]);

        $this->dispatchDomainEvent(new SalesOrderCreditCheckedEvent(
            salesOrderId: (string) $order->id,
            customerId: $customerId,
            creditStatus: $status,
            currentExposure: (float) $projectedExposure,
            creditLimit: (float) $limit,
            holdReason: $reason,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return ['approved' => $status !== 'on_hold', 'status' => $status, 'reason' => $reason, 'exposure' => $projectedExposure, 'limit' => $limit];
    }

    public function placeHold(SalesOrder $order, string $holdType, string $reason, string $userId): SalesOrderHold
    {
        SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::OnHold);
        $tenantId = (string) $order->tenant_id;

        $hold = SalesOrderHold::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'sales_order_id' => (string) $order->id,
            'hold_type' => $holdType,
            'status' => 'active',
            'hold_reason' => $reason,
            'placed_by' => Str::isUuid($userId) ? $userId : null,
            'placed_at' => now(),
        ]);

        $order->update([
            'status' => SalesOrderStatus::OnHold->value,
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

    /**
     * Releases a hold. Once no active hold is left the order goes back to confirmed (an order cancelled meanwhile stays
     * cancelled). A hold is released once.
     */
    public function releaseHold(SalesOrderHold $hold, string $userId, ?string $notes = null): SalesOrderHold
    {
        return DB::transaction(function () use ($hold, $userId, $notes): SalesOrderHold {
            $locked = SalesOrderHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('sales.credit_hold_not_active')]);
            }

            $locked->update([
                'status' => 'released',
                'released_by' => Str::isUuid($userId) ? $userId : null,
                'released_at' => now(),
                'release_notes' => $notes,
            ]);

            $order = SalesOrder::query()->whereKey($locked->sales_order_id)->lockForUpdate()->first();

            if ($order !== null) {
                $remainingHolds = SalesOrderHold::query()->where('sales_order_id', (string) $order->id)->where('status', 'active')->count();

                if ($remainingHolds === 0) {
                    if ($order->status === SalesOrderStatus::OnHold->value) {
                        SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::Confirmed);
                    }

                    $order->update(array_merge(
                        $order->status === SalesOrderStatus::OnHold->value ? ['status' => SalesOrderStatus::Confirmed->value, 'confirmed_at' => $order->confirmed_at ?? now()] : [],
                        ['credit_status' => 'approved', 'credit_hold_reason' => null]
                    ));
                }

                $this->dispatchDomainEvent(new SalesOrderHoldReleasedEvent(
                    holdId: (string) $locked->id,
                    salesOrderId: (string) $order->id,
                    releasedBy: $userId,
                    releaseNotes: $notes,
                    tenantId: (string) $order->tenant_id,
                    companyId: (string) $order->tenant_id
                ));
            }

            $hold->setRawAttributes($locked->getAttributes(), true);

            return $hold;
        });
    }

    public function updateCreditLimit(string $customerId, string|float $newLimit, string $userId, string $tenantId): CustomerCreditProfile
    {
        $limit = Decimal::round2(DocumentTotalsCalculator::decimal($newLimit));

        $profile = DB::transaction(function () use ($customerId, $limit, $userId, $tenantId): CustomerCreditProfile {
            $profile = CustomerCreditProfile::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'customer_id' => $customerId],
                ['id' => (string) Str::uuid7(), 'credit_limit' => '0']
            );
            $profile = CustomerCreditProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $oldLimit = DocumentTotalsCalculator::decimal($profile->credit_limit);

            $profile->update([
                'credit_limit' => $limit,
                'evaluated_by' => Str::isUuid($userId) ? $userId : null,
            ]);

            $this->dispatchDomainEvent(new CustomerCreditLimitUpdatedEvent(
                creditProfileId: (string) $profile->id,
                customerId: $customerId,
                oldLimit: (float) $oldLimit,
                newLimit: (float) $limit,
                updatedBy: $userId,
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            return $profile;
        });

        return $this->evaluateCredit($customerId, $tenantId)->fresh() ?? $profile;
    }

    /**
     * What the customer still owes on issued invoices (approved or posted, not proforma, not credit notes).
     */
    private function openReceivables(string $customerId, string $tenantId): string
    {
        return SalesInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::Posted->value])
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->get(['total_gross', 'total_paid'])
            ->reduce(static fn (string $sum, SalesInvoice $invoice): string => Decimal::add($sum, Decimal::sub(DocumentTotalsCalculator::decimal($invoice->total_gross), DocumentTotalsCalculator::decimal($invoice->total_paid))), '0');
    }

    /**
     * Gross value of the committed orders of the customer (confirmed up to in fulfilment) still to be billed: the
     * unbilled share of each order line, valued at its net plus its VAT rate.
     */
    private function committedOrders(string $customerId, string $tenantId, ?string $excludeOrderId): string
    {
        $orders = SalesOrder::query()
            ->with('items')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::Approved->value, SalesOrderStatus::OnHold->value, SalesOrderStatus::InProduction->value, SalesOrderStatus::InFulfillment->value, SalesOrderStatus::Shipped->value])
            ->where('billing_status', '!=', 'billed')
            ->when($excludeOrderId !== null, static fn ($query) => $query->whereKeyNot($excludeOrderId))
            ->get();

        $total = '0';

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $quantity = DocumentTotalsCalculator::decimal($item->quantity);
                $open = Decimal::sub($quantity, DocumentTotalsCalculator::decimal($item->quantity_invoiced));

                if (! Decimal::isPositive($open) || ! Decimal::isPositive($quantity)) {
                    continue;
                }

                $net = Decimal::div(Decimal::mul(DocumentTotalsCalculator::decimal($item->line_total), $open), $quantity);
                $gross = Decimal::mul($net, Decimal::add('1', Decimal::div(DocumentTotalsCalculator::decimal($item->tax_rate), '100')));
                $total = Decimal::add($total, $gross);
            }
        }

        return Decimal::round2($total);
    }

    /**
     * Overdue receivables of the customer from its open ledger entries: amount, number of invoices and the age in days
     * of the oldest overdue one, on the calendar of the company.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function overdue(string $customerId, string $tenantId): array
    {
        $today = ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId));
        $amount = '0';
        $count = 0;
        $maxDays = 0;

        $entries = CustomerLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['open', 'partially_cleared'])
            ->where('entry_type', 'invoice')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->get(['debit_amount', 'cleared_amount', 'due_date']);

        foreach ($entries as $entry) {
            $outstanding = Decimal::sub(DocumentTotalsCalculator::decimal($entry->debit_amount), DocumentTotalsCalculator::decimal($entry->cleared_amount));

            if (! Decimal::isPositive($outstanding)) {
                continue;
            }

            $amount = Decimal::add($amount, $outstanding);
            $count++;
            $maxDays = max($maxDays, (int) $today->diffInDays(CarbonImmutable::parse((string) $entry->getRawOriginal('due_date')), true));
        }

        return [Decimal::round2($amount), $count, $maxDays];
    }

    /**
     * @return array<string, mixed>
     */
    private function preferences(string $tenantId): array
    {
        $settings = Tenant::query()->whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? (array) json_decode($settings, true) : (array) $settings;

        return (array) ($settings['preferences']['sales'] ?? []);
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publishAndDispatch($event);
    }
}
