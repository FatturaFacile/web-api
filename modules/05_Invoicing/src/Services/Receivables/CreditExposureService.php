<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderStatus;
use App\Services\Sales\DocumentTotalsCalculator;
use Carbon\CarbonInterface;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

/**
 * Total credit exposure of a customer:
 *
 *   exposure = open invoices + unbilled delivery notes + confirmed orders not yet delivered - unreconciled payments
 *
 * - open invoices: the open amount of the receivable items;
 * - unbilled delivery notes: issued notes (DDT) not invoiced yet, valued at the unit price plus VAT of their lines;
 * - confirmed orders: the undelivered share of every line of the orders from confirmation to fulfilment (what is
 *   delivered is already in a delivery note, so nothing is counted twice);
 * - unreconciled payments: payments on account not yet allocated to an open item.
 */
final class CreditExposureService
{
    public function __construct(
        private readonly ReceivableItemService $items,
        private readonly ReceivableReconciliationService $reconciliation,
    ) {}

    /**
     * @return array{open_invoices: string, unbilled_delivery_notes: string, confirmed_orders: string, unreconciled_payments: string, total_exposure: string, overdue_amount: string, overdue_items: int, max_days_past_due: int}
     */
    public function calculate(string $tenantId, string $customerId, CarbonInterface $today, ?string $excludeOrderId = null, bool $sync = true): array
    {
        if ($sync) {
            $this->items->syncTenant($tenantId, $customerId);
        }

        $open = '0';
        $overdue = '0';
        $overdueCount = 0;
        $maxDays = 0;

        ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('open_amount', '>', 0)
            ->where('status', '!=', ReceivableItem::StatusWrittenOff)
            ->get()
            ->each(function (ReceivableItem $item) use (&$open, &$overdue, &$overdueCount, &$maxDays, $today): void {
                $open = Decimal::add($open, (string) $item->open_amount);
                $days = $item->daysPastDue($today);

                if ($days > 0) {
                    $overdue = Decimal::add($overdue, (string) $item->open_amount);
                    $overdueCount++;
                    $maxDays = max($maxDays, $days);
                }
            });

        $payments = array_reduce(
            $this->reconciliation->unreconciledPayments($tenantId, $customerId),
            static fn (string $sum, array $row): string => Decimal::add($sum, $row['unallocated']),
            '0',
        );

        $ddts = $this->unbilledDeliveryNotes($tenantId, $customerId);
        $orders = $this->confirmedOrders($tenantId, $customerId, $excludeOrderId);
        $total = Decimal::sub(Decimal::add(Decimal::add($open, $ddts), $orders), $payments);

        return [
            'open_invoices' => Decimal::round2($open),
            'unbilled_delivery_notes' => Decimal::round2($ddts),
            'confirmed_orders' => Decimal::round2($orders),
            'unreconciled_payments' => Decimal::round2($payments),
            'total_exposure' => Decimal::round2($total),
            'overdue_amount' => Decimal::round2($overdue),
            'overdue_items' => $overdueCount,
            'max_days_past_due' => $maxDays,
        ];
    }

    private function unbilledDeliveryNotes(string $tenantId, string $customerId): string
    {
        $total = '0';

        SalesDeliveryNote::query()
            ->with('items')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->where('status', DeliveryNoteStatus::Issued->value)
            ->get()
            ->each(function (SalesDeliveryNote $note) use (&$total): void {
                foreach ($note->items as $line) {
                    $net = Decimal::mul(DocumentTotalsCalculator::decimal($line->quantity), DocumentTotalsCalculator::decimal($line->unit_price));
                    $gross = Decimal::mul($net, Decimal::add('1', Decimal::div(DocumentTotalsCalculator::decimal($line->tax_rate), '100')));
                    $total = Decimal::add($total, $gross);
                }
            });

        return Decimal::round2($total);
    }

    private function confirmedOrders(string $tenantId, string $customerId, ?string $excludeOrderId): string
    {
        $total = '0';

        SalesOrder::query()
            ->with('items')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::Approved->value, SalesOrderStatus::OnHold->value, SalesOrderStatus::InProduction->value, SalesOrderStatus::InFulfillment->value])
            ->when($excludeOrderId !== null, static fn ($query) => $query->whereKeyNot($excludeOrderId))
            ->get()
            ->each(function (SalesOrder $order) use (&$total): void {
                foreach ($order->items as $line) {
                    $quantity = DocumentTotalsCalculator::decimal($line->quantity);
                    $undelivered = Decimal::sub($quantity, DocumentTotalsCalculator::decimal($line->quantity_delivered));

                    if (! Decimal::isPositive($undelivered) || ! Decimal::isPositive($quantity)) {
                        continue;
                    }

                    $net = Decimal::div(Decimal::mul(DocumentTotalsCalculator::decimal($line->line_total), $undelivered), $quantity);
                    $total = Decimal::add($total, Decimal::mul($net, Decimal::add('1', Decimal::div(DocumentTotalsCalculator::decimal($line->tax_rate), '100'))));
                }
            });

        return Decimal::round2($total);
    }
}
