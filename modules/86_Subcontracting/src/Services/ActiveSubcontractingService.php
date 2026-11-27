<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Events\ActiveGoodsDelivered;
use Modules\Subcontracting\Events\CustomerMaterialsReceived;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;
use Modules\Subcontracting\Support\OrderStatusResolver;

/**
 * Active subcontracting (conto lavoro attivo / Lohnfertigung): the customer's material is received into a segregated customer-goods
 * warehouse that is owned by the customer, so it is never a company asset and every ledger line carries 0.00; it is consumed by the
 * production (Mod 064), and the processed goods go back with the DDT "Reso lavorato" while the transformation fee is prepared for
 * invoicing.
 */
final class ActiveSubcontractingService
{
    public function __construct(
        private readonly SubcontractingOrderService $orders,
        private readonly SubcontractDdtService $ddt,
        private readonly SubcontractStockMover $stock,
        private readonly SubcontractLedgerService $ledger,
        private readonly BackflushPlanner $planner,
    ) {}

    /**
     * Receives the customer's material with the customer's own DDT (conto lavoro).
     *
     * @param  array{lines?: list<array{component_id: string, quantity: string}>|null, date?: string|null, customer_ddt_number?: string|null, customer_ddt_date?: string|null, customer_ddt_id?: string|null, notes?: string|null}  $data
     */
    public function receiveCustomerMaterials(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openActive($order, [OrderStatus::Confirmed, OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get()->keyBy('id');
            $wanted = $this->requested($data['lines'] ?? null, $components, static fn (SubcontractingOrderComponent $c): string => $c->toDispatchQuantity(), 'receipt_exceeds_plan');

            if ($wanted === []) {
                throw new SubcontractingException('nothing_to_receive');
            }

            $date = (string) ($data['date'] ?? now()->toDateString());
            $document = [
                'id' => $data['customer_ddt_id'] ?? null,
                'number' => $data['customer_ddt_number'] ?? null,
                'date' => $data['customer_ddt_date'] ?? $date,
                'reason' => 'subcontracting',
            ];
            $total = '0';

            foreach ($wanted as $componentId => $quantity) {
                $component = $components[$componentId];
                // Zero asset value: the stock sits in a warehouse the customer owns, which the valuation reports leave out.
                $this->stock->receive($locked, $component->component_product_id, (string) $locked->subcontractor_warehouse_id, $quantity, 'customer_material_receipt', 'Customer material '.$locked->order_number);
                $component->update(['dispatched_qty' => Money::qty(Money::add($component->dispatched_qty, $quantity)), 'updated_by' => $actorId]);
                $this->ledger->post($locked, $component, LedgerMovementType::CustomerMaterialReceipt, ['dispatched' => $quantity], $document, $date, $data['notes'] ?? null);
                $total = Money::add($total, $quantity);
            }

            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value, 'updated_by' => $actorId]);

            CustomerMaterialsReceived::dispatch((string) $locked->id, (string) $locked->tenant_id, Money::qty($total), $document['id']);

            return $locked->load('components');
        });
    }

    /**
     * Records the production on the customer's material (the Mod 064 production order consuming it): the components are consumed
     * from the customer warehouse and the processed goods are booked into it, still owned by the customer.
     *
     * @param  array<string, mixed>  $data  good_quantity, scrap_quantity, date, production_order_id, components (component_id, consumed_qty, scrap_qty)
     */
    public function recordProduction(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openActive($order, [OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get()->keyBy('id');
            $plan = $this->planner->plan($locked, $components->map(static fn (SubcontractingOrderComponent $c): SubcontractingOrderComponent => $c), $data);
            $date = (string) ($data['date'] ?? now()->toDateString());
            $warehouseId = (string) $locked->subcontractor_warehouse_id;

            if (($data['production_order_id'] ?? null) !== null) {
                if (! MfgProductionOrder::query()->whereKey($data['production_order_id'])->exists()) {
                    throw new SubcontractingException('production_order_not_found');
                }
                $locked->linked_production_order_id = (string) $data['production_order_id'];
            }

            foreach ($plan['lines'] as $line) {
                $component = $components[$line['component_id']];

                if (Money::isPositive($line['consumed'])) {
                    $this->stock->consume($locked, $line['product_id'], $warehouseId, $line['consumed'], 'customer_material_consumption', 'Production on customer material '.$locked->order_number);
                }
                if (Money::isPositive($line['scrap'])) {
                    $this->stock->consume($locked, $line['product_id'], $warehouseId, $line['scrap'], 'customer_material_scrap', 'Scrap on customer material '.$locked->order_number);
                }

                $component->update([
                    'consumed_qty' => Money::qty(Money::add($component->consumed_qty, $line['consumed'])),
                    'scrap_qty' => Money::qty(Money::add($component->scrap_qty, $line['scrap'])),
                    'updated_by' => $actorId,
                ]);

                if (Money::isPositive($line['consumed'])) {
                    $this->ledger->post($locked, $component, LedgerMovementType::InboundConsumptionBackflush, ['consumed' => $line['consumed']], [], $date);
                }
                if (Money::isPositive($line['scrap'])) {
                    $this->ledger->post($locked, $component, LedgerMovementType::ScrapDeclared, ['scrap' => $line['scrap']], [], $date);
                }
            }

            if (Money::isPositive($plan['good_quantity'])) {
                $this->stock->receive($locked, (string) $locked->output_product_id, $warehouseId, $plan['good_quantity'], 'customer_finished_receipt', 'Processed customer goods '.$locked->order_number);
            }

            $locked->received_quantity = Money::qty(Money::add($locked->received_quantity, $plan['good_quantity']));
            $locked->scrap_quantity = Money::qty(Money::add($locked->scrap_quantity, $plan['scrap_quantity']));
            $locked->updated_by = $actorId;
            $locked->save();
            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value]);

            return $locked->load('components');
        });
    }

    /**
     * Returns processed goods (and, on request, the unused material) to the customer with the DDT "Reso lavorato" and prepares the
     * transformation service invoice.
     *
     * @param  array{quantity?: string|null, return_unused_materials?: bool|null, date?: string|null, carrier_name?: string|null, notes?: string|null}  $data
     */
    public function deliver(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openActive($order, [OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get();
            $available = Money::qty(Money::sub($locked->received_quantity, $locked->delivered_quantity));
            $quantity = Money::qty($data['quantity'] ?? $available);
            $returnUnused = (bool) ($data['return_unused_materials'] ?? false);

            if (Money::isNegative($quantity) || (! Money::isPositive($quantity) && ! $returnUnused)) {
                throw new SubcontractingException('quantity_must_be_positive');
            }
            if (Money::compare($quantity, $available) > 0) {
                throw new SubcontractingException('delivery_exceeds_processed', 422, ['available' => $available]);
            }

            $items = [];
            if (Money::isPositive($quantity)) {
                $items[] = ['product_id' => (string) $locked->output_product_id, 'description' => $this->planner->productName((string) $locked->output_product_id), 'quantity' => Money::qtyToFloat($quantity)];
            }

            $unused = [];
            if ($returnUnused) {
                foreach ($components as $component) {
                    $held = $component->heldQuantity();
                    if (Money::isPositive($held)) {
                        $unused[(string) $component->id] = $held;
                        $items[] = ['product_id' => $component->component_product_id, 'description' => $this->planner->productName($component->component_product_id), 'quantity' => Money::qtyToFloat($held), 'uom' => $component->unit_of_measure];
                    }
                }
            }

            if ($items === []) {
                throw new SubcontractingException('nothing_to_deliver');
            }

            $document = $this->ddt->issue(DeliveryNoteType::SubcontractingFinishedReturn, array_filter([
                'contact_id' => $locked->partner_id,
                'origin_warehouse_id' => $locked->subcontractor_warehouse_id,
                'date' => $data['date'] ?? null,
                'carrier_name' => $data['carrier_name'] ?? null,
                'notes' => $data['notes'] ?? 'Reso lavorato '.$locked->order_number,
            ], static fn (mixed $v): bool => $v !== null), $items);

            $locked->delivered_quantity = Money::qty(Money::add($locked->delivered_quantity, $quantity));
            $locked->outgoing_material_ddt_id = $document['id'];
            $locked->updated_by = $actorId;
            $locked->save();

            if (Money::isPositive($quantity)) {
                $this->ledger->postFor($locked, (string) $locked->output_product_id, Money::sub($locked->received_quantity, $locked->delivered_quantity), null, LedgerMovementType::CustomerFinishedReturn, ['dispatched' => $quantity], $document, $document['date']);
            }
            foreach ($unused as $componentId => $held) {
                $component = $components->firstWhere('id', $componentId);
                $component->update(['returned_unused_qty' => Money::qty(Money::add($component->returned_unused_qty, $held)), 'updated_by' => $actorId]);
                $this->ledger->post($locked, $component, LedgerMovementType::UnusedMaterialReturn, ['returned_unused' => $held], $document, $document['date']);
            }

            $locked->service_billing = $this->billingBasis($locked, $document['number']);
            $locked->save();
            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value]);

            if ($locked->status === OrderStatus::Completed && $locked->completed_at === null) {
                $locked->update(['completed_at' => now()]);
            }

            ActiveGoodsDelivered::dispatch((string) $locked->id, (string) $locked->tenant_id, $quantity, $document['id']);

            return $locked->load('components');
        });
    }

    /**
     * The basis of the transformation service invoice: what was delivered so far at the agreed fee. It is a draft for the invoicing
     * module, never an issued document.
     *
     * @return array<string, mixed>
     */
    private function billingBasis(SubcontractingOrder $order, string $ddtNumber): array
    {
        $previous = $order->service_billing ?? [];
        $ddts = array_values(array_unique([...($previous['ddt_numbers'] ?? []), $ddtNumber]));
        $quantity = Money::qty($order->delivered_quantity);

        return [
            'customer_id' => $order->partner_id,
            'customer_name' => $order->partner_name,
            'description' => 'Lavorazione c/terzi '.$order->order_number.' – '.(string) (Product::query()->whereKey($order->output_product_id)->value('name') ?? ''),
            'quantity' => $quantity,
            'unit_price' => Money::round($order->unit_service_cost, 4),
            'amount' => Money::round(Money::mul($quantity, $order->unit_service_cost), 2),
            'currency' => $order->currency,
            'ddt_numbers' => $ddts,
            'sales_order_id' => $order->linked_customer_sales_order_id,
            'prepared_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function openActive(SubcontractingOrder $order, array $allowed): SubcontractingOrder
    {
        $locked = $this->orders->lock($order);

        if ($locked->direction !== Direction::Active) {
            throw new SubcontractingException('direction_mismatch');
        }
        if (! in_array($locked->status, $allowed, true)) {
            throw new SubcontractingException('invalid_status_for_action', 422, ['status' => $locked->status->value]);
        }

        return $locked;
    }

    /**
     * @param  list<array{component_id: string, quantity: string}>|null  $lines
     * @param  Collection<string, SubcontractingOrderComponent>  $components
     * @param  callable(SubcontractingOrderComponent): string  $limit
     * @return array<string, string>
     */
    private function requested(?array $lines, $components, callable $limit, string $exceedsCode): array
    {
        $wanted = [];

        if ($lines === null || $lines === []) {
            foreach ($components as $component) {
                $quantity = $limit($component);
                if (Money::isPositive($quantity)) {
                    $wanted[(string) $component->id] = Money::qty($quantity);
                }
            }

            return $wanted;
        }

        foreach ($lines as $line) {
            $component = $components->get((string) $line['component_id']);

            if (! $component instanceof SubcontractingOrderComponent) {
                throw new SubcontractingException('component_not_in_order');
            }

            $quantity = Money::qty($line['quantity']);

            if (! Money::isPositive($quantity)) {
                throw new SubcontractingException('quantity_must_be_positive');
            }
            if (Money::compare(Money::add($wanted[(string) $component->id] ?? '0', $quantity), $limit($component)) > 0) {
                throw new SubcontractingException($exceedsCode, 422, ['product' => $this->planner->productName($component->component_product_id), 'max' => $limit($component)]);
            }

            $wanted[(string) $component->id] = Money::qty(Money::add($wanted[(string) $component->id] ?? '0', $quantity));
        }

        return $wanted;
    }
}
