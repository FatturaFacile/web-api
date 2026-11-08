<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Events\SubcontractOrderReceivedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgSubcontractOrder;
use Modules\Manufacturing\Models\MfgSubcontractOrderLine;
use Modules\Manufacturing\Support\Money;

/**
 * Subcontracted operations. Sending transfers the components to the subcontractor's warehouse (still company stock,
 * no ledger entry). Receiving consumes the components sent in proportion to the processed quantity (Dr WIP, Cr raw
 * materials), accrues the processing of the good quantity at the agreed unit cost (Dr WIP, Cr GR/IR: the supplier
 * invoice clears it in Procurement) and confirms the operation. Cancelling an order with nothing received brings the
 * components back.
 */
final readonly class SubcontractingService
{
    public function __construct(
        private ProductionExecutionService $execution,
        private ManufacturingStockService $stock,
        private ManufacturingAccountingService $accounting,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Sends components to a subcontractor for a quantity of a subcontracted operation.
     *
     * @param  array{production_order_operation_id: string, subcontractor_contact_id: string, subcontract_warehouse_id: string, source_warehouse_id?: string|null, quantity: string, unit_cost?: string|null, posting_date?: string|null, notes?: string|null, components?: list<array{line_id: string, quantity: string}>|null}  $data
     */
    public function send(MfgProductionOrder $order, array $data, ?string $actorUserId): MfgSubcontractOrder
    {
        return DB::transaction(function () use ($order, $data, $actorUserId): MfgSubcontractOrder {
            $locked = $this->execution->lockOpen($order);
            $date = $this->execution->postingDate($locked, $data['posting_date'] ?? null);
            $operation = MfgProductionOrderOperation::query()->where('production_order_id', $locked->id)->whereKey($data['production_order_operation_id'])->lockForUpdate()->first();

            if (! $operation instanceof MfgProductionOrderOperation || ! $operation->is_subcontracted) {
                throw new ManufacturingOperationException('The operation is not a subcontracted operation of the order.', 'production_order_operation_id');
            }

            $quantity = Money::qty($data['quantity']);
            $pending = MfgSubcontractOrder::query()->where('production_order_operation_id', $operation->id)->whereIn('status', ['sent', 'partially_received'])->get()
                ->reduce(static fn (string $sum, MfgSubcontractOrder $sub): string => Money::add($sum, Money::sub(Money::sub($sub->quantity, $sub->received_quantity), $sub->scrapped_quantity)), '0');
            $open = Money::sub(Money::sub(Money::sub($locked->target_quantity, $operation->completed_quantity), $operation->scrapped_quantity), $pending);
            if (! Money::isPositive($quantity) || Money::compare($quantity, $open) > 0) {
                throw new ManufacturingOperationException('The quantity must be greater than zero and at most the open quantity '.Money::qty(Money::max('0', $open)).' of the operation.', 'quantity');
            }

            $source = $this->stock->warehouseFor((string) $locked->tenant_id, $data['source_warehouse_id'] ?? $locked->warehouse_id);
            $destination = $this->stock->warehouseFor((string) $locked->tenant_id, $data['subcontract_warehouse_id']);

            $subcontract = MfgSubcontractOrder::create([
                'tenant_id' => $locked->tenant_id,
                'subcontract_number' => 'SUB-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'production_order_id' => $locked->id,
                'production_order_operation_id' => $operation->id,
                'subcontractor_contact_id' => $data['subcontractor_contact_id'],
                'source_warehouse_id' => $source->id,
                'subcontract_warehouse_id' => $destination->id,
                'status' => 'sent',
                'quantity' => $quantity,
                'unit_cost' => Money::round($data['unit_cost'] ?? $operation->subcontract_unit_cost, 4),
                'sent_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ]);

            foreach ($this->componentsToSend($locked, $operation, $quantity, $data['components'] ?? null) as [$line, $componentQuantity]) {
                $component = MfgItem::query()->where('tenant_id', $locked->tenant_id)->findOrFail($line->mfg_item_id);
                $this->stock->releaseReservation($line->reservation_id);
                $line->update(['allocated_quantity' => '0', 'reservation_id' => null]);

                $moved = $this->stock->transfer($component, $source, $destination, $componentQuantity, [
                    'reference_id' => (string) $locked->id,
                    'movement_type' => 'subcontract_transfer',
                    'notes' => "Sent to subcontractor for {$subcontract->subcontract_number}",
                    'executed_at' => $date,
                    'user_id' => $actorUserId,
                ]);

                MfgSubcontractOrderLine::create([
                    'tenant_id' => $locked->tenant_id,
                    'subcontract_order_id' => $subcontract->id,
                    'production_order_line_id' => $line->id,
                    'mfg_item_id' => $component->id,
                    'quantity_sent' => $componentQuantity,
                ]);

                MfgMaterialMovement::create([
                    'tenant_id' => $locked->tenant_id,
                    'production_order_id' => $locked->id,
                    'production_order_line_id' => $line->id,
                    'subcontract_order_id' => $subcontract->id,
                    'movement_type' => MfgMaterialMovement::SubcontractSend,
                    'mfg_item_id' => $component->id,
                    'warehouse_id' => $destination->id,
                    'quantity' => $componentQuantity,
                    'unit_cost' => Money::round(Money::div($moved['total_cost'], $componentQuantity), 6),
                    'total_cost' => $moved['total_cost'],
                    'stock_movement_id' => $moved['movements'][0]->id ?? null,
                    'posting_date' => $date,
                    'created_by' => $subcontract->created_by,
                ]);
            }

            return $subcontract->load('lines');
        });
    }

    /**
     * Receives processed quantity back from the subcontractor.
     */
    public function receive(MfgSubcontractOrder $subcontract, string $goodQuantity, string $scrapQuantity, ?string $postingDate, ?string $actorUserId): MfgSubcontractOrder
    {
        return DB::transaction(function () use ($subcontract, $goodQuantity, $scrapQuantity, $postingDate, $actorUserId): MfgSubcontractOrder {
            $order = $this->execution->lockOpen(MfgProductionOrder::query()->findOrFail($subcontract->production_order_id));
            $locked = MfgSubcontractOrder::query()->whereKey($subcontract->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ManufacturingOperationException("Subcontract order [{$locked->subcontract_number}] is {$locked->status}.", 'status');
            }

            $date = $this->execution->postingDate($order, $postingDate);
            $good = Money::qty($goodQuantity);
            $scrap = Money::qty($scrapQuantity);
            $processed = Money::add($good, $scrap);
            $held = Money::sub(Money::sub($locked->quantity, $locked->received_quantity), $locked->scrapped_quantity);
            if (! Money::isPositive($processed) || Money::isNegative($good) || Money::isNegative($scrap) || Money::compare($processed, $held) > 0) {
                throw new ManufacturingOperationException('The quantity received must be greater than zero and at most the quantity '.Money::qty($held).' still at the subcontractor.', 'good_quantity');
            }

            $isLast = Money::compare($processed, $held) === 0;
            $warehouse = $this->stock->warehouseFor((string) $order->tenant_id, $locked->subcontract_warehouse_id);
            $lines = MfgSubcontractOrderLine::query()->where('subcontract_order_id', $locked->id)->lockForUpdate()->get();

            foreach ($lines as $subLine) {
                $quantity = $isLast ? Money::qty($subLine->quantityHeld()) : Money::qty(Money::mul($subLine->quantity_sent, Money::div($processed, $locked->quantity)));
                if (! Money::isPositive($quantity)) {
                    continue;
                }

                $line = MfgProductionOrderLine::query()->whereKey($subLine->production_order_line_id)->lockForUpdate()->firstOrFail();
                $this->execution->consume($order, $line, $quantity, $warehouse, MfgMaterialMovement::SubcontractConsume, $date, $actorUserId, null, (string) $locked->id);
                $subLine->update(['quantity_consumed' => Money::qty(Money::add($subLine->quantity_consumed, $quantity))]);
            }

            $serviceCost = Money::round(Money::mul($locked->unit_cost, $good));
            $this->accounting->postSubcontractService($order, $serviceCost, $date, (string) $locked->id, "Subcontracted processing received: {$locked->subcontract_number}", $actorUserId);
            $order->actual_subcontract_cost = Money::round(Money::add($order->actual_subcontract_cost, $serviceCost), 4);
            $order->save();

            $operation = MfgProductionOrderOperation::query()->whereKey($locked->production_order_operation_id)->lockForUpdate()->firstOrFail();
            $operationDone = Money::compare(Money::add(Money::add($operation->completed_quantity, $operation->scrapped_quantity), $processed), $order->target_quantity) >= 0;
            $this->execution->recordConfirmation($order, $operation, new ConfirmOperationDTO(
                goodQuantity: $good,
                scrapQuantity: $scrap,
                laborMinutes: '0',
                isFinal: $operationDone,
                postingDate: $date,
            ), $serviceCost, (string) $locked->id, $actorUserId);

            $locked->update([
                'received_quantity' => Money::qty(Money::add($locked->received_quantity, $good)),
                'scrapped_quantity' => Money::qty(Money::add($locked->scrapped_quantity, $scrap)),
                'total_cost' => Money::round(Money::add($locked->total_cost, $serviceCost), 4),
                'status' => $isLast ? 'received' : 'partially_received',
                'received_at' => now(),
            ]);

            $this->dispatcher->publishAndDispatch(new SubcontractOrderReceivedEvent(
                subcontractOrderId: (string) $locked->id,
                productionOrderId: (string) $order->id,
                subcontractorContactId: (string) $locked->subcontractor_contact_id,
                receivedQuantity: $good,
                serviceCost: $serviceCost,
                tenantId: (string) $order->tenant_id
            ));

            return $locked->load('lines');
        });
    }

    /**
     * Cancels a subcontract order with nothing received: the components come back to the source warehouse.
     */
    public function cancel(MfgSubcontractOrder $subcontract, ?string $actorUserId): MfgSubcontractOrder
    {
        return DB::transaction(function () use ($subcontract, $actorUserId): MfgSubcontractOrder {
            $locked = MfgSubcontractOrder::query()->whereKey($subcontract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'sent') {
                throw new ManufacturingOperationException("Subcontract order [{$locked->subcontract_number}] is {$locked->status}: only orders with nothing received can be cancelled.", 'status');
            }

            $order = MfgProductionOrder::query()->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            $from = $this->stock->warehouseFor((string) $locked->tenant_id, $locked->subcontract_warehouse_id);
            $to = $this->stock->warehouseFor((string) $locked->tenant_id, $locked->source_warehouse_id);

            foreach (MfgSubcontractOrderLine::query()->where('subcontract_order_id', $locked->id)->lockForUpdate()->get() as $subLine) {
                $quantity = Money::qty($subLine->quantityHeld());
                if (! Money::isPositive($quantity)) {
                    continue;
                }

                $component = MfgItem::query()->where('tenant_id', $locked->tenant_id)->findOrFail($subLine->mfg_item_id);
                $moved = $this->stock->transfer($component, $from, $to, $quantity, [
                    'reference_id' => (string) $order->id,
                    'movement_type' => 'subcontract_transfer',
                    'notes' => "Returned from subcontractor for {$locked->subcontract_number}",
                    'user_id' => $actorUserId,
                ]);

                MfgMaterialMovement::create([
                    'tenant_id' => $locked->tenant_id,
                    'production_order_id' => $order->id,
                    'production_order_line_id' => $subLine->production_order_line_id,
                    'subcontract_order_id' => $locked->id,
                    'movement_type' => MfgMaterialMovement::SubcontractReturn,
                    'mfg_item_id' => $component->id,
                    'warehouse_id' => $to->id,
                    'quantity' => $quantity,
                    'unit_cost' => Money::round(Money::div($moved['total_cost'], $quantity), 6),
                    'total_cost' => $moved['total_cost'],
                    'stock_movement_id' => $moved['movements'][0]->id ?? null,
                    'posting_date' => now()->toDateString(),
                ]);
                $subLine->update(['quantity_returned' => Money::qty(Money::add($subLine->quantity_returned, $quantity))]);
            }

            $locked->update(['status' => 'cancelled']);

            return $locked->load('lines');
        });
    }

    /**
     * The components to send: those given, else the lines tied to the operation in proportion to the quantity.
     *
     * @param  list<array{line_id: string, quantity: string}>|null  $requested
     * @return list<array{0: MfgProductionOrderLine, 1: string}>
     */
    private function componentsToSend(MfgProductionOrder $order, MfgProductionOrderOperation $operation, string $quantity, ?array $requested): array
    {
        $result = [];

        if ($requested !== null && $requested !== []) {
            foreach ($requested as $row) {
                $line = MfgProductionOrderLine::query()->where('production_order_id', $order->id)->whereKey($row['line_id'])->lockForUpdate()->first();
                if (! $line instanceof MfgProductionOrderLine) {
                    throw new ManufacturingOperationException('The component line does not belong to the production order.', 'components');
                }
                $componentQuantity = Money::qty($row['quantity']);
                if (! Money::isPositive($componentQuantity)) {
                    throw new ManufacturingOperationException('The quantity of a component to send must be greater than zero.', 'components');
                }
                $result[] = [$line, $componentQuantity];
            }

            return $result;
        }

        $lines = MfgProductionOrderLine::query()->where('production_order_id', $order->id)->where('operation_sequence', $operation->sequence)->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $componentQuantity = Money::qty(Money::min(Money::mul($line->quantity_per_unit, $quantity), $line->openQuantity()));
            if (Money::isPositive($componentQuantity)) {
                $result[] = [$line, $componentQuantity];
            }
        }

        return $result;
    }
}
