<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Enums\PurchaseOrderDispatchChannel;
use Modules\Procurement\Events\PurchaseOrderMilestoneEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseOrderReminder;

/**
 * Finds the orders whose delivery date (promised by the supplier, else requested) has passed with open lines, and sends
 * the supplier reminders: one at most every `$intervalDays`, escalating by level up to `$maxLevel`. The reminder log
 * and the counters on the order are the audit trail; delivery is done by the modules that consume the event.
 */
final readonly class OverdueOrderReminderService
{
    public const int DefaultIntervalDays = 7;

    public const int DefaultMaxLevel = 3;

    /** Statuses of an order the supplier already holds and that still expects goods. */
    private const array OpenStatuses = ['sent', 'confirmed', 'partially_received'];

    public function __construct(
        private ProcurementAuditLedgerService $auditLedger,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Open overdue orders with the days late and the lines that are late, the latest delay first.
     *
     * @return Collection<int, array{order: PurchaseOrder, days_overdue: int, overdue_line_ids: list<string>, next_reminder_due: bool}>
     */
    public function overdue(?string $tenantId = null, ?CarbonImmutable $today = null, int $intervalDays = self::DefaultIntervalDays): Collection
    {
        $today ??= CarbonImmutable::now()->startOfDay();

        $query = PurchaseOrder::query()->whereIn('status', self::OpenStatuses)->with(['lines', 'supplier']);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $rows = collect();

        foreach ($query->orderBy('date')->get() as $order) {
            $lineIds = [];
            $days = 0;

            foreach ($order->lines as $line) {
                $due = $this->dueDate($order, $line);

                if ($line->is_closed || $due === null || ! $due->lt($today)) {
                    continue;
                }

                $lineIds[] = (string) $line->id;
                $days = max($days, (int) $due->diffInDays($today));
            }

            if ($lineIds === []) {
                continue;
            }

            $last = $order->last_reminder_at !== null ? CarbonImmutable::parse($order->last_reminder_at)->startOfDay() : null;

            $rows->push([
                'order' => $order,
                'days_overdue' => $days,
                'overdue_line_ids' => $lineIds,
                'next_reminder_due' => $last === null || $last->addDays($intervalDays)->lte($today),
            ]);
        }

        return $rows->sortByDesc('days_overdue')->values();
    }

    /**
     * Sends the automatic reminders that are due; returns how many were sent.
     */
    public function sendDue(?string $tenantId = null, ?CarbonImmutable $today = null, int $intervalDays = self::DefaultIntervalDays, int $maxLevel = self::DefaultMaxLevel): int
    {
        $sent = 0;

        foreach ($this->overdue($tenantId, $today, $intervalDays) as $row) {
            if ($row['next_reminder_due'] && (int) $row['order']->reminder_count < $maxLevel) {
                $this->remind($row['order'], $row['days_overdue'], $row['overdue_line_ids'], 'auto');
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Manual reminder of one order.
     *
     * @throws ProcurementRuleViolation when the order is not overdue
     */
    public function remindOrder(PurchaseOrder $order, ?string $actorId = null, ?CarbonImmutable $today = null): PurchaseOrderReminder
    {
        $row = $this->overdue((string) $order->tenant_id, $today)->first(static fn (array $row): bool => $row['order']->id === $order->id);

        if ($row === null) {
            throw new ProcurementRuleViolation('The order has no overdue open lines.', 'status');
        }

        return $this->remind($row['order'], $row['days_overdue'], $row['overdue_line_ids'], 'manual', $actorId);
    }

    /**
     * @param  list<string>  $lineIds
     */
    private function remind(PurchaseOrder $order, int $days, array $lineIds, string $trigger, ?string $actorId = null): PurchaseOrderReminder
    {
        return DB::transaction(function () use ($order, $days, $lineIds, $trigger, $actorId): PurchaseOrderReminder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $level = (int) $locked->reminder_count + 1;
            $channel = PurchaseOrderDispatchChannel::tryFrom((string) $locked->dispatch_channel) ?? PurchaseOrderDispatchChannel::Email;
            $number = (string) ($locked->po_number ?? $locked->order_number);

            $reminder = PurchaseOrderReminder::create([
                'tenant_id' => $locked->tenant_id,
                'purchase_order_id' => $locked->id,
                'level' => $level,
                'channel' => $channel === PurchaseOrderDispatchChannel::Pdf ? PurchaseOrderDispatchChannel::Email->value : $channel->value,
                'trigger' => $trigger,
                'days_overdue' => $days,
                'overdue_line_ids' => $lineIds,
                'message_reference' => 'REM-'.$number.'-'.$level.'-'.Str::upper(Str::random(6)),
                'sent_at' => now(),
                'sent_by' => $actorId,
            ]);

            $locked->update(['reminder_count' => $level, 'last_reminder_at' => now()]);
            $this->auditLedger->record(action: 'PURCHASE_ORDER_REMINDER_SENT', model: $locked, payloadBefore: ['reminder_count' => $level - 1], payloadAfter: ['reminder_count' => $level, 'days_overdue' => $days, 'trigger' => $trigger]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderMilestoneEvent(
                name: PurchaseOrderMilestoneEvent::ReminderSent,
                purchaseOrderId: (string) $locked->id,
                poNumber: $number,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                data: ['supplier_id' => $locked->supplier_id, 'level' => $level, 'days_overdue' => $days, 'channel' => $reminder->channel, 'message_reference' => $reminder->message_reference, 'overdue_line_ids' => $lineIds],
            ));

            return $reminder;
        });
    }

    private function dueDate(PurchaseOrder $order, PurchaseOrderLine $line): ?CarbonImmutable
    {
        $date = $line->promised_delivery_date ?? $line->confirmed_delivery_date ?? $line->expected_delivery_date ?? $order->promised_delivery_date ?? $order->expected_delivery_date;

        return $date !== null ? CarbonImmutable::parse($date)->startOfDay() : null;
    }
}
