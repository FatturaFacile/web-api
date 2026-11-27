<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Events\MaterialsDispatchedToSubcontractor;
use Modules\Subcontracting\Events\SubcontractedGoodsReceived;
use Modules\Subcontracting\Events\UnusedMaterialsReturned;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;
use Modules\Subcontracting\Support\OrderStatusResolver;

/**
 * Passive subcontracting (conto lavoro passivo): dispatch of our material to the vendor's virtual warehouse with the DDT "Conto
 * lavoro", receipt of the processed goods with automatic backflush of the consumed components and declared scrap, return of the
 * unused material, and capitalization of the external processing fee into the unit cost of the goods received.
 *
 * @phpstan-import-type ReceiptPlan from BackflushPlanner
 */
final class PassiveSubcontractingService
{
    public function __construct(
        private readonly SubcontractingOrderService $orders,
        private readonly SubcontractDdtService $ddt,
        private readonly SubcontractStockMover $stock,
        private readonly SubcontractLedgerService $ledger,
        private readonly BackflushPlanner $planner,
    ) {}

    /**
     * Sends components to the vendor. Without a line list the whole quantity still to dispatch goes out.
     *
     * @param  array{lines?: list<array{component_id: string, quantity: string}>|null, date?: string|null, carrier_name?: string|null, notes?: string|null}  $data
     */
    public function dispatch(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openPassive($order, [OrderStatus::Confirmed, OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get()->keyBy('id');
            $wanted = $this->requestedQuantities($data['lines'] ?? null, $components, static fn (SubcontractingOrderComponent $c): string => $c->toDispatchQuantity(), 'dispatch_exceeds_plan');

            if ($wanted === []) {
                throw new SubcontractingException('nothing_to_dispatch');
            }

            $items = [];
            foreach ($wanted as $componentId => $quantity) {
                $component = $components[$componentId];
                $items[] = [
                    'product_id' => $component->component_product_id,
                    'description' => $this->planner->productName($component->component_product_id),
                    'quantity' => Money::qtyToFloat($quantity),
                    'uom' => $component->unit_of_measure,
                    'batch_number' => $component->lot_id,
                    'serial_number' => $component->serial_number,
                ];
            }

            $document = $this->ddt->issue(DeliveryNoteType::SubcontractingOut, array_filter([
                'supplier_id' => $locked->partner_id,
                'origin_warehouse_id' => $locked->source_warehouse_id,
                'destination_warehouse_id' => $locked->subcontractor_warehouse_id,
                'date' => $data['date'] ?? null,
                'carrier_name' => $data['carrier_name'] ?? null,
                'notes' => $data['notes'] ?? 'Conto lavoro '.$locked->order_number,
            ], static fn (mixed $v): bool => $v !== null), $items);

            $total = '0';
            foreach ($wanted as $componentId => $quantity) {
                $component = $components[$componentId];
                $component->update(['dispatched_qty' => Money::qty(Money::add($component->dispatched_qty, $quantity)), 'updated_by' => $actorId]);
                $this->ledger->post($locked, $component, LedgerMovementType::OutboundDispatch, ['dispatched' => $quantity], $document, $document['date']);
                $total = Money::add($total, $quantity);
            }

            $locked->outgoing_material_ddt_id = $document['id'];
            $locked->status = OrderStatus::MaterialsDispatched;
            $locked->save();
            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value, 'updated_by' => $actorId]);

            MaterialsDispatchedToSubcontractor::dispatch((string) $locked->id, (string) $locked->tenant_id, Money::qty($total), $document['id']);

            return $locked->load('components');
        });
    }

    /**
     * What a receipt would do, without writing: components consumed and scrapped, their value, the processing fee and the unit cost
     * the received goods would be capitalized at.
     *
     * @param  array<string, mixed>  $data
     * @return ReceiptPlan
     */
    public function preview(SubcontractingOrder $order, array $data): array
    {
        $this->assertPassive($order);

        return $this->planner->plan($order, $order->components()->get()->keyBy('id'), $data);
    }

    /**
     * Receives processed goods: backflushes the components consumed, declares the scrap, books the goods in at the capitalized cost.
     *
     * @param  array<string, mixed>  $data  good_quantity, scrap_quantity, date, vendor_ddt_number, vendor_ddt_date, inbound_document_type/id, output_warehouse_id, components, notes
     */
    public function receive(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openPassive($order, [OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get()->keyBy('id');
            $plan = $this->planner->plan($locked, $components, $data);
            $date = (string) ($data['date'] ?? now()->toDateString());
            $document = [
                'id' => $data['inbound_document_id'] ?? null,
                'number' => $data['vendor_ddt_number'] ?? null,
                'date' => $data['vendor_ddt_date'] ?? $date,
                'reason' => 'subcontractor_return',
            ];
            $warehouseId = (string) $locked->subcontractor_warehouse_id;

            foreach ($plan['lines'] as $line) {
                $component = $components[$line['component_id']];

                if (Money::isPositive($line['consumed'])) {
                    $this->stock->consume($locked, $line['product_id'], $warehouseId, $line['consumed'], 'subcontract_consumption', 'Backflush '.$locked->order_number);
                }
                if (Money::isPositive($line['scrap'])) {
                    $this->stock->consume($locked, $line['product_id'], $warehouseId, $line['scrap'], 'subcontract_scrap', 'Scrap declared by the subcontractor '.$locked->order_number);
                }

                $component->update([
                    'consumed_qty' => Money::qty(Money::add($component->consumed_qty, $line['consumed'])),
                    'scrap_qty' => Money::qty(Money::add($component->scrap_qty, $line['scrap'])),
                    'updated_by' => $actorId,
                ]);

                if (Money::isPositive($line['consumed'])) {
                    $this->ledger->post($locked, $component, LedgerMovementType::InboundConsumptionBackflush, ['consumed' => $line['consumed']], $document, $date);
                }
                if (Money::isPositive($line['scrap'])) {
                    $this->ledger->post($locked, $component, LedgerMovementType::ScrapDeclared, ['scrap' => $line['scrap']], $document, $date, $data['notes'] ?? null);
                }
            }

            if (Money::isPositive($plan['good_quantity'])) {
                $this->stock->receive(
                    $locked,
                    (string) $locked->output_product_id,
                    (string) ($data['output_warehouse_id'] ?? $locked->source_warehouse_id),
                    $plan['good_quantity'],
                    'subcontract_receipt',
                    'Processed goods received '.$locked->order_number,
                    $plan['unit_cost'],
                );
            }

            $locked->received_quantity = Money::qty(Money::add($locked->received_quantity, $plan['good_quantity']));
            $locked->scrap_quantity = Money::qty(Money::add($locked->scrap_quantity, $plan['scrap_quantity']));
            $locked->capitalized_service_amount = Money::round(Money::add($locked->capitalized_service_amount, $plan['service_amount']), 4);
            $locked->capitalized_unit_cost = Money::isPositive($plan['good_quantity']) ? $plan['unit_cost'] : $locked->capitalized_unit_cost;
            $locked->inbound_return_document_type = $data['inbound_document_type'] ?? $locked->inbound_return_document_type;
            $locked->inbound_return_document_id = $data['inbound_document_id'] ?? $locked->inbound_return_document_id;
            $locked->inbound_return_document_number = $data['vendor_ddt_number'] ?? $locked->inbound_return_document_number;
            $locked->updated_by = $actorId;
            $locked->save();
            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value, 'completed_at' => null]);
            $this->stampCompletion($locked);

            SubcontractedGoodsReceived::dispatch((string) $locked->id, (string) $locked->tenant_id, $plan['good_quantity'], $document['id']);

            return $locked->load('components');
        });
    }

    /**
     * Brings unworked material back to the company warehouse with the DDT "Reso da terzista". Without a line list everything still
     * at the vendor comes back.
     *
     * @param  array{lines?: list<array{component_id: string, quantity: string}>|null, date?: string|null, notes?: string|null}  $data
     */
    public function returnUnused(SubcontractingOrder $order, array $data, ?string $actorId): SubcontractingOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): SubcontractingOrder {
            $locked = $this->openPassive($order, [OrderStatus::MaterialsDispatched, OrderStatus::InProgress, OrderStatus::PartiallyReturned]);
            $components = $locked->components()->lockForUpdate()->get()->keyBy('id');
            $wanted = $this->requestedQuantities($data['lines'] ?? null, $components, static fn (SubcontractingOrderComponent $c): string => $c->heldQuantity(), 'return_exceeds_held');

            if ($wanted === []) {
                throw new SubcontractingException('nothing_to_return');
            }

            $items = [];
            foreach ($wanted as $componentId => $quantity) {
                $component = $components[$componentId];
                $items[] = [
                    'product_id' => $component->component_product_id,
                    'description' => $this->planner->productName($component->component_product_id),
                    'quantity' => Money::qtyToFloat($quantity),
                    'uom' => $component->unit_of_measure,
                ];
            }

            $document = $this->ddt->issue(DeliveryNoteType::SubcontractingReturn, array_filter([
                'supplier_id' => $locked->partner_id,
                'origin_warehouse_id' => $locked->subcontractor_warehouse_id,
                'destination_warehouse_id' => $locked->source_warehouse_id,
                'date' => $data['date'] ?? null,
                'notes' => $data['notes'] ?? 'Reso da terzista '.$locked->order_number,
            ], static fn (mixed $v): bool => $v !== null), $items);

            $total = '0';
            foreach ($wanted as $componentId => $quantity) {
                $component = $components[$componentId];
                $component->update(['returned_unused_qty' => Money::qty(Money::add($component->returned_unused_qty, $quantity)), 'updated_by' => $actorId]);
                $this->ledger->post($locked, $component, LedgerMovementType::UnusedMaterialReturn, ['returned_unused' => $quantity], $document, $document['date']);
                $total = Money::add($total, $quantity);
            }

            $locked->update(['status' => OrderStatusResolver::resolve($locked)->value, 'updated_by' => $actorId]);
            $this->stampCompletion($locked);

            UnusedMaterialsReturned::dispatch((string) $locked->id, (string) $locked->tenant_id, Money::qty($total), $document['id']);

            return $locked->load('components');
        });
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function openPassive(SubcontractingOrder $order, array $allowed): SubcontractingOrder
    {
        $locked = $this->orders->lock($order);
        $this->assertPassive($locked);

        if (! in_array($locked->status, $allowed, true)) {
            throw new SubcontractingException('invalid_status_for_action', 422, ['status' => $locked->status->value]);
        }

        return $locked;
    }

    private function assertPassive(SubcontractingOrder $order): void
    {
        if ($order->direction !== Direction::Passive) {
            throw new SubcontractingException('direction_mismatch');
        }
    }

    /**
     * @param  list<array{component_id: string, quantity: string}>|null  $lines
     * @param  Collection<string, SubcontractingOrderComponent>  $components
     * @param  callable(SubcontractingOrderComponent): string  $limit
     * @return array<string, string> component id => quantity
     */
    private function requestedQuantities(?array $lines, $components, callable $limit, string $exceedsCode): array
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

    private function stampCompletion(SubcontractingOrder $order): void
    {
        if ($order->status === OrderStatus::Completed && $order->completed_at === null) {
            $order->update(['completed_at' => now()]);
        }
    }
}
