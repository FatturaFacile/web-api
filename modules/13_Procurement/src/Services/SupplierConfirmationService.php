<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Events\PurchaseOrderMilestoneEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderConfirmation;
use Modules\Procurement\Models\PurchaseOrderConfirmationLine;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Support\Decimal;

/**
 * Records the order confirmation of the supplier: its number, the confirmed quantities, prices and delivery dates, and
 * the variances against the order. The promised dates reach MRP (it reads `purchase_orders.delivery_due_date`) and the
 * linked sales order, so availability and delivery promises follow the supplier.
 */
final readonly class SupplierConfirmationService
{
    public function __construct(
        private PurchasePricingService $pricing,
        private ProcurementAuditLedgerService $auditLedger,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{confirmation_number: string, confirmed_at?: string|null, confirmed_delivery_date?: string|null, notes?: string|null, lines?: array<int, array<string, mixed>>}  $data
     *
     * @throws ProcurementRuleViolation when the order cannot be confirmed or a line is not part of it
     */
    public function record(PurchaseOrder $order, array $data, ?string $actorId = null): PurchaseOrderConfirmation
    {
        return DB::transaction(function () use ($order, $data, $actorId): PurchaseOrderConfirmation {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;

            if (! in_array($from, [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Confirmed->value], true)) {
                throw new ProcurementRuleViolation("An order in status {$from} cannot be confirmed by the supplier.", 'status');
            }

            $supplier = Supplier::query()->findOrFail($locked->supplier_id);
            $tolerance = Decimal::of($supplier->price_tolerance_percent ?? '0', 4);
            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $locked->id)->get()->keyBy(static fn (PurchaseOrderLine $line): string => (string) $line->id);
            $given = [];

            foreach (array_values($data['lines'] ?? []) as $index => $line) {
                $lineId = (string) ($line['purchase_order_line_id'] ?? '');

                if (! $orderLines->has($lineId)) {
                    throw new ProcurementRuleViolation('Line '.($index + 1).' does not belong to the purchase order.', "lines.{$index}.purchase_order_line_id");
                }

                $given[$lineId] = $line;
            }

            $confirmation = PurchaseOrderConfirmation::create([
                'tenant_id' => $locked->tenant_id,
                'purchase_order_id' => $locked->id,
                'confirmation_number' => $data['confirmation_number'],
                'confirmed_at' => $data['confirmed_at'] ?? now()->toDateString(),
                'confirmed_by' => $actorId,
                'notes' => $data['notes'] ?? null,
            ]);

            $priceTotal = '0.00';
            $quantityTotal = '0.0000';
            $variance = false;
            $review = false;
            $promised = null;

            foreach ($orderLines as $lineId => $orderLine) {
                $input = $given[$lineId] ?? [];
                $quantity = Decimal::of($input['confirmed_quantity'] ?? $orderLine->quantity_ordered, 4);
                $price = Decimal::of($input['confirmed_unit_price'] ?? $orderLine->unit_price, 4);
                $date = $input['confirmed_delivery_date'] ?? $data['confirmed_delivery_date'] ?? ($orderLine->expected_delivery_date ?? $locked->expected_delivery_date)?->toDateString();
                $requested = ($orderLine->expected_delivery_date ?? $locked->expected_delivery_date)?->toDateString();

                $quantityVariance = Decimal::sub($quantity, (string) $orderLine->quantity_ordered, 4);
                $priceVariance = Decimal::sub($price, (string) $orderLine->unit_price, 4);
                $pricePercent = Decimal::isPositive((string) $orderLine->unit_price) ? Decimal::round(bcmul(bcdiv($priceVariance, (string) $orderLine->unit_price, 10), '100', 10), 4) : '0.0000';
                $totalVariance = Decimal::round(bcmul($priceVariance, $quantity, 6), 2);
                $delay = $date !== null && $requested !== null ? (int) CarbonImmutable::parse($requested)->startOfDay()->diffInDays(CarbonImmutable::parse($date)->startOfDay(), false) : 0;

                $lineVariance = Decimal::cmp($quantityVariance, '0', 4) !== 0 || Decimal::cmp($priceVariance, '0', 4) !== 0 || $delay > 0;
                $lineReview = Decimal::cmp($quantityVariance, '0', 4) !== 0 || Decimal::cmp(Decimal::abs($pricePercent, 4), $tolerance, 4) > 0;

                PurchaseOrderConfirmationLine::create([
                    'tenant_id' => $locked->tenant_id,
                    'purchase_order_confirmation_id' => $confirmation->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'ordered_quantity' => $orderLine->quantity_ordered,
                    'confirmed_quantity' => $quantity,
                    'ordered_unit_price' => $orderLine->unit_price,
                    'confirmed_unit_price' => $price,
                    'requested_delivery_date' => $requested,
                    'confirmed_delivery_date' => $date,
                    'quantity_variance' => $quantityVariance,
                    'quantity_variance_base' => $this->pricing->toBase($quantityVariance, (string) $orderLine->uom_conversion_factor),
                    'price_variance' => $priceVariance,
                    'price_variance_percent' => $pricePercent,
                    'line_total_variance' => $totalVariance,
                    'delivery_delay_days' => $delay,
                    'variance_reason' => $input['variance_reason'] ?? null,
                ]);

                $orderLine->update([
                    'quantity_confirmed' => $quantity,
                    'confirmed_delivery_date' => $date,
                    'promised_delivery_date' => $date,
                ]);

                $priceTotal = Decimal::add($priceTotal, $totalVariance, 2);
                $quantityTotal = Decimal::add($quantityTotal, $quantityVariance, 4);
                $variance = $variance || $lineVariance;
                $review = $review || $lineReview;
                $promised = $date !== null && ($promised === null || $date > $promised) ? $date : $promised;
            }

            $confirmation->update([
                'has_variance' => $variance,
                'requires_review' => $review,
                'price_variance_total' => $priceTotal,
                'quantity_variance_total' => $quantityTotal,
            ]);

            $locked->update([
                'status' => PurchaseOrderStatus::Confirmed->value,
                'supplier_confirmation_number' => $data['confirmation_number'],
                'confirmed_at' => now(),
                'promised_delivery_date' => $promised,
                // MRP and the delivery reminders read this date: it now is the date the supplier promised.
                'delivery_due_date' => $promised ?? $locked->delivery_due_date,
            ]);

            $this->propagateToSalesOrder($locked, $promised);

            $this->auditLedger->record(action: 'PURCHASE_ORDER_CONFIRMED', model: $locked, payloadBefore: ['status' => $from], payloadAfter: ['confirmation_number' => $data['confirmation_number'], 'promised_delivery_date' => $promised, 'has_variance' => $variance]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderMilestoneEvent(
                name: PurchaseOrderMilestoneEvent::Confirmed,
                purchaseOrderId: (string) $locked->id,
                poNumber: (string) ($locked->po_number ?? $locked->order_number),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                data: ['confirmation_number' => $data['confirmation_number'], 'promised_delivery_date' => $promised, 'has_variance' => $variance, 'requires_review' => $review, 'sales_order_id' => $locked->sales_order_id],
            ));

            return $confirmation->fresh(['lines']) ?? $confirmation;
        });
    }

    /**
     * Linked sales orders (dropshipment) see the promised date as their confirmed delivery date.
     */
    private function propagateToSalesOrder(PurchaseOrder $order, ?string $promised): void
    {
        if ($order->sales_order_id === null || $promised === null) {
            return;
        }

        DB::table('sales_orders')
            ->where('id', $order->sales_order_id)
            ->where('tenant_id', $order->tenant_id)
            ->update(['confirmed_delivery_date' => $promised, 'updated_at' => now()]);
    }
}
