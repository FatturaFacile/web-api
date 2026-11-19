<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\SalesOrderStatus;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Events\SalesOrderCreditBlockedEvent;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

/**
 * Credit control of the sales orders on top of the exposure of CustomerCreditService: explains why an order is blocked
 * (limit exceeded, overdue invoices past the grace period, or both), records the block and runs the release workflow
 * of the credit manager. The grace period is the `credit_block_overdue_days` preference of the company.
 *
 * TotalExposure = open receivables + committed unbilled orders + the current order.
 */
final class CustomerCreditControlService
{
    public const string LimitExceeded = 'credit_limit_exceeded';

    public const string OverduePresent = 'overdue_invoices_present';

    public const string Both = 'both';

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly SalesOrderAuditTrail $audit,
        private readonly CustomerCreditServiceInterface $credit,
    ) {}

    /**
     * Exposure figures of the customer of an order, from its (already evaluated) credit profile.
     *
     * @return array{credit_limit: string, open_receivables: string, committed_orders: string, overdue_amount: string, overdue_invoices_count: int, max_overdue_days: int, order_total: string, total_exposure: string, over_limit: bool, has_overdue: bool, block_reason: ?string}
     */
    public function snapshot(SalesOrder $order): array
    {
        $profile = CustomerCreditProfile::query()->where('tenant_id', $order->tenant_id)->where('customer_id', $order->contact_id)->first();
        $limit = bcadd((string) ($profile->credit_limit ?? '0'), '0', 2);
        $open = bcadd((string) ($profile->used_credit ?? '0'), '0', 2);
        $committed = bcadd((string) ($profile->pending_orders_amount ?? '0'), '0', 2);
        $orderTotal = bcadd((string) $order->total_gross, '0', 2);
        $exposure = bcadd(bcadd($open, $committed, 2), $orderTotal, 2);
        $overLimit = bccomp($limit, '0', 2) > 0 && bccomp($exposure, $limit, 2) > 0;
        $hasOverdue = $profile !== null && $profile->credit_status === 'blocked';

        return [
            'credit_limit' => $limit,
            'open_receivables' => $open,
            'committed_orders' => $committed,
            'overdue_amount' => bcadd((string) ($profile->overdue_amount ?? '0'), '0', 2),
            'overdue_invoices_count' => (int) ($profile->overdue_invoices_count ?? 0),
            'max_overdue_days' => (int) ($profile->max_overdue_days ?? 0),
            'order_total' => $orderTotal,
            'total_exposure' => $exposure,
            'over_limit' => $overLimit,
            'has_overdue' => $hasOverdue,
            'block_reason' => match (true) {
                $overLimit && $hasOverdue => self::Both,
                $overLimit => self::LimitExceeded,
                $hasOverdue => self::OverduePresent,
                default => null,
            },
        ];
    }

    /**
     * Records why a held order is blocked and tells the rest of the system.
     */
    public function recordBlock(SalesOrder $order, ?string $actorId = null): void
    {
        $figures = $this->snapshot($order);
        $order->update(['credit_block_reason' => $figures['block_reason'] ?? self::LimitExceeded]);
        $this->audit->record($order, 'credit_blocked', $figures, $actorId, null, SalesOrderStatus::OnHold->value);
        $this->events->publishAndDispatch(new SalesOrderCreditBlockedEvent((string) $order->id, (string) $order->tenant_id, (string) $order->tenant_id, $figures));
    }

    /**
     * The credit manager releases a blocked order: the credit holds are released, the approver and the justification
     * are stored and the order is confirmed again.
     *
     * @throws ValidationException when the order is not blocked by credit
     */
    public function release(SalesOrder $order, string $userId, string $notes): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId, $notes): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $holds = SalesOrderHold::query()->where('sales_order_id', (string) $locked->id)->where('status', 'active')->where('hold_type', 'credit_limit')->get();

            if ($locked->status !== SalesOrderStatus::OnHold->value || $holds->isEmpty()) {
                throw ValidationException::withMessages(['status' => __('sales.order_not_credit_blocked')]);
            }

            foreach ($holds as $hold) {
                $this->credit->releaseHold($hold, $userId, $notes);
            }

            $locked->refresh();
            $locked->update([
                'credit_approved_by' => Str::isUuid($userId) ? $userId : null,
                'credit_approved_at' => now(),
                'credit_override_notes' => $notes,
            ]);
            $this->audit->record($locked, 'credit_released', ['notes' => $notes], $userId, SalesOrderStatus::OnHold->value, (string) $locked->status);

            return $locked;
        });
    }
}
