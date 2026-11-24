<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\SalesOrderStatus;
use App\Services\Sales\DocumentTotalsCalculator;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * Orchestrates the confirmation of a sales order (credit check, ATP annotation, stock allocation, replenishment) and
 * its shipment in partial deliveries with the three ways of handling what stays open: keep it open, split it into a
 * backorder line with a recalculated promise date, or cancel it. Keeps the progress percentages of the header.
 *
 * Splitting shrinks the shipped line to what was delivered and moves the residual into a new backorder line (same
 * price, parent_line_id pointing at the original), so the order total does not change.
 */
final class SalesOrderFulfillmentService
{
    public const string KeepOpen = 'keep_open';

    public const string Backorder = 'backorder';

    public const string CancelResidual = 'cancel_residual';

    /**
     * @var list<string>
     */
    public const array ResidualModes = [self::KeepOpen, self::Backorder, self::CancelResidual];

    public function __construct(
        private readonly SalesOrderServiceInterface $orders,
        private readonly DeliveryFulfillmentServiceInterface $delivery,
        private readonly AtpCtpCalculationService $atp,
        private readonly ReplenishmentTriggerService $replenishment,
        private readonly BlanketAgreementService $blanketAgreements,
        private readonly SalesOrderAuditTrail $audit,
    ) {}

    /**
     * Confirms the order behind the credit check; a confirmed order gets its ATP annotated, stock reserved and, when
     * asked, its shortages pegged to replenishment. A blocked order waits for the credit release.
     */
    public function confirm(SalesOrder $order, string $userId, bool $autoReplenish = true): SalesOrder
    {
        $from = (string) $order->status;
        $confirmed = $this->orders->submitOrder($order, $userId);
        $this->audit->record($confirmed, 'status_changed', ['credit_status' => $confirmed->credit_status], $userId, $from, (string) $confirmed->status);

        if ($confirmed->status === SalesOrderStatus::Confirmed->value) {
            $this->allocate($confirmed, $userId, $autoReplenish);
        }

        return $confirmed->refresh()->load('items');
    }

    /**
     * ATP annotation, stock reservation and optional replenishment of a confirmed order (also after a credit release).
     */
    public function allocate(SalesOrder $order, ?string $userId, bool $autoReplenish): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId, $autoReplenish): SalesOrder {
            $this->annotateAtp($order, $userId);
            $reserved = $this->orders->reserveInventoryIn($order, $order->warehouse_id, $userId);

            if ($autoReplenish) {
                $this->replenishment->generateForOrder($reserved, $userId);
            }

            $this->refreshProgress($reserved);

            return $reserved->refresh()->load('items');
        });
    }

    /**
     * Recalculates the ATP/CTP promise of every open stock line and stores it on the line and, as the confirmed
     * delivery date, on the order header when none is set.
     */
    public function annotateAtp(SalesOrder $order, ?string $userId = null): void
    {
        $order->load('items');
        $summary = [];
        $latest = null;

        foreach ($order->items as $item) {
            $open = $this->openQuantity($item);

            if ($item->product_id === null || bccomp($open, '0', 4) <= 0) {
                continue;
            }

            $check = $this->atp->check((string) $order->tenant_id, (string) $item->product_id, $open, $item->warehouse_id ?? $order->warehouse_id, ($item->requested_date ?? $order->requested_delivery_date)?->toDateString());
            $promised = $check['earliest_atp_date'] ?? $check['ctp_estimated_date'];
            $item->update(['atp_status' => $check['atp_status'], 'atp_promised_date' => $promised]);
            $summary[(string) $item->id] = ['atp_status' => $check['atp_status'], 'promised' => $promised, 'shortage' => $check['shortage_qty']];

            if ($promised !== null && ($latest === null || $promised > $latest)) {
                $latest = $promised;
            }
        }

        if ($latest !== null && $order->confirmed_delivery_date === null) {
            $order->update(['confirmed_delivery_date' => $latest]);
        }

        if ($summary !== []) {
            $this->audit->record($order, 'atp_recalculated', ['lines' => $summary], $userId);
        }
    }

    /**
     * Ships the order from the delivery note API: the DDT is created and issued for the quantities given
     * (line id => quantity, every open quantity when empty), then the residual of each shipped line is handled.
     *
     * @param  array<string, float|int|string>  $lines
     * @param  array<string, mixed>  $transport
     */
    public function ship(SalesOrder $order, array $lines, array $transport, string $residual, ?string $userId): SalesDeliveryNote
    {
        return DB::transaction(function () use ($order, $lines, $transport, $residual, $userId): SalesDeliveryNote {
            $items = [];

            foreach ($lines as $itemId => $quantity) {
                $items[] = ['sales_order_item_id' => (string) $itemId, 'quantity' => (string) $quantity];
            }

            $note = $this->delivery->createDeliveryNote($order, $transport + ['created_by' => $userId], $items);
            $this->delivery->dispatchDelivery($note, $userId ?? 'system');

            $order->refresh()->load('items');
            $shipped = $lines === [] ? null : array_map('strval', array_keys($lines));

            foreach ($order->items as $item) {
                if ($shipped !== null && ! in_array((string) $item->id, $shipped, true)) {
                    continue;
                }

                if (bccomp($this->openQuantity($item), '0', 4) > 0 && bccomp((string) $item->quantity_delivered, '0', 4) > 0) {
                    match ($residual) {
                        self::Backorder => $this->splitBackorder($order, $item, $userId),
                        self::CancelResidual => $this->cancelResidual($order, $item, $userId),
                        default => null,
                    };
                }
            }

            $this->refreshStatus($order->refresh()->load('items'));
            $this->refreshProgress($order);

            return $note;
        });
    }

    /**
     * Header progress: the share of the order allocated (reserved or delivered), delivered and invoiced, weighted by the
     * value of the lines (equally when the order has no value).
     */
    public function refreshProgress(SalesOrder $order): SalesOrder
    {
        $items = SalesOrderItem::withoutGlobalScope(TenantScope::class)->where('sales_order_id', (string) $order->id)->get();
        $weights = [];
        $allocated = $delivered = $invoiced = '0';
        $total = '0';

        foreach ($items as $item) {
            $quantity = bcadd((string) $item->quantity, '0', 4);

            if (bccomp($quantity, '0', 4) <= 0) {
                continue;
            }

            $weight = bccomp((string) $item->line_total, '0', 4) > 0 ? (string) $item->line_total : '0';
            $weights[] = [$item, $quantity, $weight];
            $total = bcadd($total, $weight, 4);
        }

        $equal = bccomp($total, '0', 4) <= 0;

        foreach ($weights as [$item, $quantity, $weight]) {
            $share = $equal ? bcdiv('1', (string) count($weights), 8) : bcdiv($weight, $total, 8);
            $allocated = bcadd($allocated, bcmul($this->ratio(bcadd((string) $item->quantity_reserved, (string) $item->quantity_delivered, 4), $quantity), $share, 8), 8);
            $delivered = bcadd($delivered, bcmul($this->ratio((string) $item->quantity_delivered, $quantity), $share, 8), 8);
            $invoiced = bcadd($invoiced, bcmul($this->ratio((string) $item->quantity_invoiced, $quantity), $share, 8), 8);
        }

        // A query update: generic rows without tenant cannot be saved through the model (see SalesBillingLines).
        SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($order->id)->update([
            'allocated_percentage' => bcmul($allocated, '100', 2),
            'delivered_percentage' => bcmul($delivered, '100', 2),
            'invoiced_percentage' => bcmul($invoiced, '100', 2),
        ]);

        return $order;
    }

    /**
     * Open backorder and shortage lines of the tenant for the planners' monitor: the backorder lines and the open
     * lines whose ATP promise is not on time.
     *
     * @return LengthAwarePaginator<int, SalesOrderItem>
     */
    public function backorderMonitor(string $tenantId, int $perPage = 25): LengthAwarePaginator
    {
        return SalesOrderItem::query()
            ->with(['order.customer', 'product'])
            ->where('tenant_id', $tenantId)
            ->whereRaw('quantity - quantity_delivered - quantity_cancelled > 0')
            ->where(static fn ($query) => $query->where('is_backorder', true)->orWhereIn('atp_status', [AtpCtpCalculationService::DelayedAtp, AtpCtpCalculationService::CtpRequired, AtpCtpCalculationService::Shortage, AtpCtpCalculationService::PartialAvailability]))
            ->whereHas('order', static fn ($query) => $query->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value, SalesOrderStatus::Rejected->value, SalesOrderStatus::Completed->value]))
            ->orderBy('atp_promised_date')
            ->paginate($perPage);
    }

    private function splitBackorder(SalesOrder $order, SalesOrderItem $item, ?string $userId): void
    {
        $delivered = bcadd((string) $item->quantity_delivered, '0', 4);
        $residual = $this->openQuantity($item);

        if (bccomp((string) $item->quantity_invoiced, $delivered, 4) > 0) {
            return;
        }

        $rootId = (string) ($item->parent_line_id ?? $item->id);
        $sequence = (int) SalesOrderItem::query()->where('parent_line_id', $rootId)->max('backorder_sequence') + 1;
        $check = $item->product_id !== null
            ? $this->atp->check((string) $order->tenant_id, (string) $item->product_id, $residual, $item->warehouse_id ?? $order->warehouse_id, $item->requested_date?->toDateString())
            : null;

        $this->reprice($item, $delivered);

        $backorder = SalesOrderItem::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $item->tenant_id,
            'sales_order_id' => (string) $order->id,
            'product_id' => $item->product_id,
            'blanket_agreement_line_id' => $item->blanket_agreement_line_id,
            'warehouse_id' => $item->warehouse_id,
            'parent_line_id' => $rootId,
            'is_backorder' => true,
            'backorder_sequence' => $sequence,
            'description' => $item->description,
            'quantity' => $residual,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount_percentage' => $item->discount_percentage,
            'line_total' => $this->lineTotal($item, $residual),
            'cost_price' => $item->cost_price,
            'uom' => $item->uom,
            'notes' => $item->notes,
            'requested_date' => $item->requested_date,
            'atp_status' => $check['atp_status'] ?? null,
            'atp_promised_date' => $check === null ? null : ($check['earliest_atp_date'] ?? $check['ctp_estimated_date']),
        ]);

        $this->audit->record($order, 'backorder_split', ['original_line_id' => (string) $item->id, 'backorder_line_id' => (string) $backorder->id, 'quantity' => $residual, 'promised_date' => $backorder->atp_promised_date?->toDateString()], $userId, null, null, (string) $backorder->id);
    }

    private function cancelResidual(SalesOrder $order, SalesOrderItem $item, ?string $userId): void
    {
        $delivered = bcadd((string) $item->quantity_delivered, '0', 4);
        $residual = $this->openQuantity($item);

        if (bccomp((string) $item->quantity_invoiced, $delivered, 4) > 0) {
            return;
        }

        $this->blanketAgreements->restoreLineQuantity($order, $item, $residual);
        $this->reprice($item, $delivered);
        $item->update(['quantity_cancelled' => bcadd((string) $item->quantity_cancelled, $residual, 4)]);
        $this->recalculateHeader($order);
        $this->audit->record($order, 'residual_cancelled', ['line_id' => (string) $item->id, 'quantity' => $residual], $userId, null, null, (string) $item->id);
    }

    /**
     * Shrinks a line to a quantity: its total follows the unit price and discount.
     */
    private function reprice(SalesOrderItem $item, string $quantity): void
    {
        $item->update(['quantity' => $quantity, 'line_total' => $this->lineTotal($item, $quantity)]);
    }

    private function lineTotal(SalesOrderItem $item, string $quantity): string
    {
        $calculated = (new DocumentTotalsCalculator)->calculateDecimal([[
            'quantity' => $quantity,
            'unit_price' => (string) $item->unit_price,
            'discount_percentage' => (string) $item->discount_percentage,
            'tax_rate' => (string) $item->tax_rate,
        ]]);

        return $calculated['items'][0]['line_total'];
    }

    private function recalculateHeader(SalesOrder $order): void
    {
        $order->load('items');
        $totals = (new DocumentTotalsCalculator)->calculateDecimal($order->items->map(static fn (SalesOrderItem $line): array => [
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_percentage' => (string) $line->discount_percentage,
            'tax_rate' => (string) $line->tax_rate,
        ])->all());

        $order->update([
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['total_net'],
            'total_tax' => $totals['total_tax'],
            'total_gross' => $totals['total_gross'],
        ]);
    }

    /**
     * Fulfilled when every line is delivered: the order is shipped; otherwise it stays in fulfilment.
     */
    private function refreshStatus(SalesOrder $order): void
    {
        $fulfilled = $order->items->every(fn (SalesOrderItem $item): bool => bccomp($this->openQuantity($item), '0', 4) <= 0);

        if ($fulfilled && $order->status !== SalesOrderStatus::Shipped->value) {
            SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::Shipped);
            $order->update(['status' => SalesOrderStatus::Shipped->value, 'fulfillment_status' => 'fulfilled']);
            $this->audit->record($order, 'status_changed', [], null, SalesOrderStatus::InFulfillment->value, SalesOrderStatus::Shipped->value);
        }
    }

    private function openQuantity(SalesOrderItem $item): string
    {
        $open = bcsub(bcsub((string) $item->quantity, (string) $item->quantity_delivered, 4), (string) $item->quantity_cancelled, 4);

        return bccomp($open, '0', 4) > 0 ? $open : '0.0000';
    }

    private function ratio(string $part, string $whole): string
    {
        $ratio = bcdiv($part, $whole, 8);

        return bccomp($ratio, '1', 8) > 0 ? '1.00000000' : $ratio;
    }
}
