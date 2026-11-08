<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Events\FinishedGoodsProducedEvent;
use Modules\Manufacturing\Events\InventoryProducedEvent;
use Modules\Manufacturing\Events\MaterialConsumedEvent;
use Modules\Manufacturing\Events\OperationConfirmedEvent;
use Modules\Manufacturing\Events\ProductionOrderClosedEvent;
use Modules\Manufacturing\Events\ProductionOrderCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderReleasedEvent;
use Modules\Manufacturing\Events\ProductionVarianceReadyEvent;
use Modules\Manufacturing\Events\WIPRecognizedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgOperationConfirmation;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgSubcontractOrder;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\Money;

/**
 * Executes production orders on the single stock ledger and the General Ledger. Every state change runs in one
 * transaction with the order row locked: a stock movement, its manufacturing record and its journal entry are written
 * together or not at all (no open fiscal period, missing stock and invalid quantities roll everything back).
 */
final readonly class ProductionExecutionService implements ProductionExecutionServiceInterface
{
    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private RoutingServiceInterface $routings,
        private ManufacturingStockService $stock,
        private ManufacturingAccountingService $accounting,
        private WorkCenterService $workCenters,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function releaseOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($order, $actorUserId): MfgProductionOrder {
            $locked = $this->lock($order);
            $locked->transitionTo('released');

            $item = MfgItem::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->mfg_item_id);
            $bom = MfgBom::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->bom_id);
            $routing = $locked->routing_id !== null ? MfgRouting::query()->where('tenant_id', $locked->tenant_id)->find($locked->routing_id) : null;
            $date = $locked->planned_start_date->toDateString();
            $quantity = Money::of($locked->target_quantity);
            $requirements = $this->boms->componentRequirements($bom, $quantity, $date);
            $warehouse = $this->stock->warehouseFor((string) $locked->tenant_id, $locked->warehouse_id, $item);

            $material = '0';
            foreach ($requirements as $row) {
                $component = MfgItem::query()->where('tenant_id', $locked->tenant_id)->findOrFail($row['component_item_id']);
                $this->stock->stockItemFor($component);
                $unitCost = $component->standardUnitCost();
                $material = Money::add($material, Money::mul($row['effective_quantity'], $unitCost));

                $line = MfgProductionOrderLine::create([
                    'tenant_id' => $locked->tenant_id,
                    'production_order_id' => $locked->id,
                    'mfg_item_id' => $component->id,
                    'bom_component_id' => $row['bom_component_id'],
                    'issue_method' => $row['issue_method'],
                    'operation_sequence' => $row['operation_sequence'],
                    'quantity_per_unit' => Money::round(Money::div($row['effective_quantity'], $quantity), 6),
                    'required_quantity' => $row['effective_quantity'],
                    'allocated_quantity' => '0',
                    'consumed_quantity' => '0',
                    'scrapped_quantity' => '0',
                    'uom' => $row['uom'],
                    'unit_cost' => Money::round($unitCost, 4),
                    'total_cost' => Money::round(Money::mul($row['effective_quantity'], $unitCost), 4),
                    'issued_cost' => '0',
                    'warehouse_id' => $warehouse->id,
                ]);

                $reservation = $this->stock->reserve($component, $warehouse, $row['effective_quantity'], (string) $line->id, $actorUserId);
                $line->update(['allocated_quantity' => $reservation['quantity'], 'reservation_id' => $reservation['reservation']?->id]);
            }

            $conversion = ['labor_cost' => '0', 'overhead_cost' => '0', 'subcontract_cost' => '0'];
            if ($routing instanceof MfgRouting) {
                $conversion = $this->routings->standardTimesAndCosts($routing, $quantity);
                $this->copyOperations($locked, $routing, $quantity);
            }

            $unit = $item->standard_cost_rolled_up_at !== null
                ? ['material' => $item->standard_material_cost, 'labor' => $item->standard_labor_cost, 'overhead' => $item->standard_overhead_cost, 'subcontract' => $item->standard_subcontract_cost]
                : ['material' => Money::div($material, $quantity), 'labor' => Money::div($conversion['labor_cost'], $quantity), 'overhead' => Money::div($conversion['overhead_cost'], $quantity), 'subcontract' => Money::div($conversion['subcontract_cost'], $quantity)];

            $locked->fill([
                'warehouse_id' => $warehouse->id,
                'receipt_warehouse_id' => $locked->receipt_warehouse_id ?? $warehouse->id,
                'std_unit_material_cost' => Money::round($unit['material'], 6),
                'std_unit_labor_cost' => Money::round($unit['labor'], 6),
                'std_unit_overhead_cost' => Money::round($unit['overhead'], 6),
                'std_unit_subcontract_cost' => Money::round($unit['subcontract'], 6),
                'released_at' => now(),
                'released_by' => $this->uuidOrNull($actorUserId),
                'lock_version' => $locked->lock_version + 1,
            ]);
            $locked->standard_cost_total = Money::round(Money::mul($locked->standardUnitCost(), $quantity), 4);
            $locked->save();

            $this->dispatcher->publishAndDispatch(new ProductionOrderReleasedEvent(
                productionOrderId: (string) $locked->id,
                orderNumber: $locked->order_number,
                mfgItemId: (string) $locked->mfg_item_id,
                targetQuantity: Money::qtyToFloat($quantity),
                estimatedStandardCost: Money::toFloat($locked->standard_cost_total),
                tenantId: (string) $locked->tenant_id
            ));

            return $locked->load(['lines', 'operations']);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function startOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($order): MfgProductionOrder {
            $locked = $this->lock($order);
            $locked->transitionTo('in_progress');
            $locked->save();

            return $locked->load(['lines', 'operations']);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function issueMaterials(MfgProductionOrder $order, array $lines, ?string $postingDate = null, bool $allowOverIssue = false, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($order, $lines, $postingDate, $allowOverIssue, $actorUserId): array {
            $locked = $this->lockOpen($order);
            $date = $this->postingDate($locked, $postingDate);
            $movements = [];

            foreach ($lines as $request) {
                $line = MfgProductionOrderLine::query()->where('production_order_id', $locked->id)->whereKey($request['line_id'])->lockForUpdate()->first();
                if (! $line instanceof MfgProductionOrderLine) {
                    throw new ManufacturingOperationException('The component line does not belong to the production order.', 'lines');
                }

                $quantity = Money::qty($request['quantity']);
                if (! Money::isPositive($quantity)) {
                    throw new ManufacturingOperationException('The quantity to issue must be greater than zero.', 'lines');
                }
                if (! $allowOverIssue && Money::compare($quantity, $line->openQuantity()) > 0) {
                    throw new ManufacturingOperationException('The quantity to issue exceeds the open quantity '.Money::qty($line->openQuantity()).' of the component; confirm the over-issue explicitly.', 'lines');
                }

                $movements[] = $this->consume($locked, $line, $quantity, null, MfgMaterialMovement::Issue, $date, $actorUserId, $request['lot_id'] ?? null);
            }

            $this->touchInProgress($locked);

            return $movements;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function confirmOperation(MfgProductionOrder $order, MfgProductionOrderOperation $operation, ConfirmOperationDTO $dto, ?string $actorUserId = null): MfgOperationConfirmation
    {
        return DB::transaction(function () use ($order, $operation, $dto, $actorUserId): MfgOperationConfirmation {
            $locked = $this->lockOpen($order);
            $op = MfgProductionOrderOperation::query()->where('production_order_id', $locked->id)->whereKey($operation->id)->lockForUpdate()->first();
            if (! $op instanceof MfgProductionOrderOperation) {
                throw new ManufacturingOperationException('The operation does not belong to the production order.', 'operation');
            }
            if ($op->is_subcontracted) {
                throw new ManufacturingOperationException('A subcontracted operation is confirmed by receiving its subcontract order.', 'operation');
            }

            return $this->recordConfirmation($locked, $op, $dto, '0', null, $actorUserId);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function receiveOutput(MfgProductionOrder $order, string $quantity, ?string $lotNumber = null, array $serialNumbers = [], ?string $warehouseId = null, ?string $postingDate = null, ?string $actorUserId = null): MfgMaterialMovement
    {
        return DB::transaction(function () use ($order, $quantity, $lotNumber, $serialNumbers, $warehouseId, $postingDate, $actorUserId): MfgMaterialMovement {
            $locked = $this->lockOpen($order);
            $date = $this->postingDate($locked, $postingDate);
            $quantity = Money::qty($quantity);
            $open = Money::sub($locked->target_quantity, $locked->completed_quantity);

            if (! Money::isPositive($quantity)) {
                throw new ManufacturingOperationException('The quantity received must be greater than zero.', 'quantity');
            }
            if (Money::compare($quantity, $open) > 0) {
                throw new ManufacturingOperationException('The quantity received exceeds the open quantity '.Money::qty($open).' of the order.', 'quantity');
            }

            $item = MfgItem::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->mfg_item_id);
            $warehouse = $this->stock->warehouseFor((string) $locked->tenant_id, $warehouseId ?? $locked->receipt_warehouse_id, $item);
            $sequences = MfgProductionOrderOperation::query()->where('production_order_id', $locked->id)->pluck('sequence')->map(static fn ($sequence): int => (int) $sequence)->all();

            $this->backflush($locked, $quantity, static fn (MfgProductionOrderLine $line): bool => $line->operation_sequence === null || ! in_array($line->operation_sequence, $sequences, true), $date, $actorUserId);

            $unitCost = $locked->standardUnitCost();
            $value = Money::round(Money::mul($unitCost, $quantity));
            $received = $this->stock->receive($item, $warehouse, $quantity, $unitCost, $lotNumber, $serialNumbers, [
                'reference_id' => (string) $locked->id,
                'movement_type' => 'production_receipt',
                'notes' => "Output of production order {$locked->order_number}",
                'executed_at' => $date,
                'user_id' => $actorUserId,
            ]);

            $movement = MfgMaterialMovement::create([
                'tenant_id' => $locked->tenant_id,
                'production_order_id' => $locked->id,
                'movement_type' => MfgMaterialMovement::Receipt,
                'mfg_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'unit_cost' => Money::round($unitCost, 6),
                'total_cost' => $value,
                'stock_movement_id' => $received['movements'][0]->id ?? null,
                'lot_id' => $received['lot']?->id,
                'serial_number_ids' => $received['serial_ids'] !== [] ? $received['serial_ids'] : null,
                'posting_date' => $date,
                'created_by' => $this->uuidOrNull($actorUserId),
            ]);

            $entry = $this->accounting->postOutputReceipt($locked, $value, $date, (string) $movement->id, "Output received: {$locked->order_number}", $actorUserId);
            $movement->update(['journal_entry_id' => $entry?->id]);

            if ($received['lot'] !== null) {
                $this->stock->linkLots($received['lot'], $this->componentLots($locked), (string) $locked->id);
            }

            $locked->update([
                'completed_quantity' => Money::qty(Money::add($locked->completed_quantity, $quantity)),
                'received_value' => Money::round(Money::add($locked->received_value, $value), 4),
            ]);
            $this->touchInProgress($locked);

            $this->dispatcher->publishAndDispatch(new FinishedGoodsProducedEvent(
                productionOrderId: (string) $locked->id,
                mfgItemId: (string) $item->id,
                quantityProduced: Money::qtyToFloat($quantity),
                unitCost: (float) Money::round($unitCost, 4),
                totalCost: Money::toFloat($value),
                tenantId: (string) $locked->tenant_id
            ));
            $this->dispatcher->publishAndDispatch(new InventoryProducedEvent(
                productionOrderId: (string) $locked->id,
                stockItemId: (string) $item->stock_item_id,
                quantity: Money::qtyToFloat($quantity),
                unitCost: (float) Money::round($unitCost, 4),
                tenantId: (string) $locked->tenant_id
            ));

            return $movement->refresh();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function completeOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($order): MfgProductionOrder {
            $locked = $this->lock($order);
            if (MfgSubcontractOrder::query()->where('production_order_id', $locked->id)->whereIn('status', ['sent', 'partially_received'])->exists()) {
                throw new ManufacturingOperationException('The order still has components at a subcontractor: receive or cancel the subcontract orders first.', 'status');
            }

            $locked->transitionTo('completed');
            $locked->save();
            $this->releaseReservations($locked);

            $this->dispatcher->publishAndDispatch(new ProductionOrderCompletedEvent(
                productionOrderId: (string) $locked->id,
                orderNumber: $locked->order_number,
                mfgItemId: (string) $locked->mfg_item_id,
                completedQuantity: Money::qtyToFloat($locked->completed_quantity),
                scrappedQuantity: Money::qtyToFloat($locked->scrapped_quantity),
                actualCostTotal: Money::toFloat($locked->actualCost()),
                varianceTotal: Money::toFloat($locked->wipBalance()),
                tenantId: (string) $locked->tenant_id
            ));

            return $locked->load(['lines', 'operations']);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function closeOrder(MfgProductionOrder $order, ?string $postingDate = null, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($order, $postingDate, $actorUserId): MfgProductionOrder {
            $locked = $this->lock($order);
            if ($locked->status !== 'completed') {
                throw new ManufacturingOperationException("Production order [{$locked->order_number}] must be completed before it is closed (status: {$locked->status}).", 'status');
            }

            $date = $this->postingDate($locked, $postingDate);
            $output = Money::of($locked->completed_quantity);
            $elements = [
                'material' => [$locked->std_unit_material_cost, $locked->actual_material_cost],
                'labor' => [$locked->std_unit_labor_cost, $locked->actual_labor_cost],
                'overhead' => [$locked->std_unit_overhead_cost, $locked->actual_overhead_cost],
                'subcontract' => [$locked->std_unit_subcontract_cost, $locked->actual_subcontract_cost],
            ];

            $standards = [];
            $variances = [];
            foreach ($elements as $element => [$unit, $actual]) {
                $standards[$element] = Money::round(Money::mul($unit, $output));
                $variances[$element] = Money::round(Money::sub($actual, $standards[$element]));
            }

            $residual = Money::round($locked->wipBalance());
            $rounding = Money::sub($residual, array_reduce($variances, static fn (string $sum, string $value): string => Money::add($sum, $value), '0'));
            $variances['material'] = Money::round(Money::add($variances['material'], $rounding));

            $entry = $this->accounting->postVariances($locked, $variances, $date, $actorUserId);

            foreach ($elements as $element => [, $actual]) {
                MfgProductionCost::updateOrCreate(
                    ['tenant_id' => $locked->tenant_id, 'production_order_id' => $locked->id, 'cost_type' => $element],
                    ['standard_cost' => $standards[$element], 'actual_cost' => Money::round($actual), 'variance' => $variances[$element], 'is_posted_to_gl' => $entry !== null, 'gl_journal_id' => $entry?->id]
                );
            }

            $standardOfOutput = array_reduce($standards, static fn (string $sum, string $value): string => Money::add($sum, $value), '0');
            $actualTotal = Money::round($locked->actualCost());
            $locked->transitionTo('closed');
            $locked->fill([
                'material_variance' => $variances['material'],
                'labor_variance' => $variances['labor'],
                'overhead_variance' => $variances['overhead'],
                'subcontract_variance' => $variances['subcontract'],
                'standard_cost_total' => Money::round($standardOfOutput, 4),
                'actual_cost_total' => $actualTotal,
                'variance_total' => $residual,
                'variance_journal_entry_id' => $entry?->id,
                'closed_at' => now(),
                'closed_by' => $this->uuidOrNull($actorUserId),
            ])->save();

            $this->dispatcher->publishAndDispatch(new ProductionVarianceReadyEvent(
                productionOrderId: (string) $locked->id,
                standardCost: Money::toFloat($standardOfOutput),
                actualCost: Money::toFloat($actualTotal),
                variance: Money::toFloat($residual),
                varianceType: Money::isPositive($residual) ? 'unfavorable' : 'favorable',
                tenantId: (string) $locked->tenant_id
            ));
            $this->dispatcher->publishAndDispatch(new ProductionOrderClosedEvent(
                productionOrderId: (string) $locked->id,
                orderNumber: $locked->order_number,
                receivedQuantity: Money::qty($output),
                standardCostOfOutput: Money::round($standardOfOutput),
                actualCost: $actualTotal,
                variances: $variances,
                journalEntryId: $entry?->id !== null ? (string) $entry->id : null,
                tenantId: (string) $locked->tenant_id
            ));

            return $locked->load(['lines', 'operations']);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function cancelOrder(MfgProductionOrder $order, string $reason, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($order, $reason): MfgProductionOrder {
            $locked = $this->lock($order);
            $moved = MfgMaterialMovement::query()->where('production_order_id', $locked->id)->exists()
                || MfgOperationConfirmation::query()->where('production_order_id', $locked->id)->exists();
            if ($moved) {
                throw new ManufacturingOperationException("Production order [{$locked->order_number}] has issues, confirmations or receipts: complete and close it instead of cancelling.", 'status');
            }

            $locked->transitionTo('cancelled');
            $locked->notes = trim(($locked->notes !== null && $locked->notes !== '' ? $locked->notes."\n" : '')."Cancelled: {$reason}");
            $locked->save();
            $this->releaseReservations($locked);

            return $locked->load(['lines', 'operations']);
        });
    }

    /**
     * Consumes a component into WIP: stock issue (or consumption at a subcontractor), manufacturing movement, journal
     * entry and line totals. The line's reservation is released before the issue and renewed for what stays open.
     * Runs inside the caller's transaction with the order and the line locked.
     */
    public function consume(MfgProductionOrder $order, MfgProductionOrderLine $line, string $quantity, ?Warehouse $warehouse, string $movementType, string $date, ?string $actorUserId, ?string $lotId = null, ?string $subcontractOrderId = null): MfgMaterialMovement
    {
        $component = MfgItem::query()->where('tenant_id', $order->tenant_id)->findOrFail($line->mfg_item_id);
        $fromReservedStock = $warehouse === null;
        $warehouse ??= $this->stock->warehouseFor((string) $order->tenant_id, $line->warehouse_id ?? $order->warehouse_id, $component);

        if ($fromReservedStock) {
            $this->stock->releaseReservation($line->reservation_id);
        }

        $issued = $this->stock->issue($component, $warehouse, $quantity, $lotId, [
            'reference_id' => (string) $order->id,
            'movement_type' => $movementType === MfgMaterialMovement::SubcontractConsume ? 'subcontract_consumption' : 'production_issue',
            'notes' => "Component of production order {$order->order_number}",
            'executed_at' => $date,
            'user_id' => $actorUserId,
        ]);
        $cost = $issued['total_cost'];
        $lots = array_keys($issued['lots']);

        $movement = MfgMaterialMovement::create([
            'tenant_id' => $order->tenant_id,
            'production_order_id' => $order->id,
            'production_order_line_id' => $line->id,
            'subcontract_order_id' => $subcontractOrderId,
            'movement_type' => $movementType,
            'mfg_item_id' => $component->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'unit_cost' => Money::round(Money::div($cost, $quantity), 6),
            'total_cost' => $cost,
            'stock_movement_id' => $issued['movements'][0]->id ?? null,
            'lot_id' => count($lots) === 1 ? $lots[0] : null,
            'posting_date' => $date,
            'created_by' => $this->uuidOrNull($actorUserId),
        ]);

        $entry = $this->accounting->postMaterialIssue($order, $cost, $date, 'mfg_material_movement', (string) $movement->id, "Components issued: {$order->order_number} ({$component->item_code})", $actorUserId);
        $movement->update(['journal_entry_id' => $entry?->id]);

        $line->consumed_quantity = Money::qty(Money::add($line->consumed_quantity, $quantity));
        $line->issued_cost = Money::round(Money::add($line->issued_cost, $cost), 4);
        $line->allocated_quantity = '0';
        $line->reservation_id = $fromReservedStock ? null : $line->reservation_id;
        $line->save();

        if ($fromReservedStock && Money::isPositive($line->openQuantity()) && $order->isOpen()) {
            $reservation = $this->stock->reserve($component, $warehouse, $line->openQuantity(), (string) $line->id, $actorUserId);
            $line->update(['allocated_quantity' => $reservation['quantity'], 'reservation_id' => $reservation['reservation']?->id]);
        }

        $order->actual_material_cost = Money::round(Money::add($order->actual_material_cost, $cost), 4);
        $order->save();

        $this->dispatcher->publishAndDispatch(new MaterialConsumedEvent(
            productionOrderId: (string) $order->id,
            componentItemId: (string) $component->id,
            quantityConsumed: Money::qtyToFloat($quantity),
            unitCost: (float) Money::round(Money::div($cost, $quantity), 4),
            totalCost: Money::toFloat($cost),
            tenantId: (string) $order->tenant_id
        ));
        $this->dispatcher->publishAndDispatch(new WIPRecognizedEvent(
            productionOrderId: (string) $order->id,
            costType: 'material',
            amount: Money::toFloat($cost),
            currency: $this->currency((string) $order->tenant_id),
            tenantId: (string) $order->tenant_id
        ));

        return $movement->refresh();
    }

    /**
     * Records an operation confirmation (shop floor, manual or subcontract receipt): updates the operation and the
     * order, absorbs labour and overhead into WIP and backflushes the components of the operation. Runs inside the
     * caller's transaction with the order and the operation locked.
     */
    public function recordConfirmation(MfgProductionOrder $order, MfgProductionOrderOperation $op, ConfirmOperationDTO $dto, string $subcontractCost, ?string $subcontractOrderId, ?string $actorUserId): MfgOperationConfirmation
    {
        if ($op->isCompleted()) {
            throw new ManufacturingOperationException("Operation {$op->sequence} is already completed.", 'operation');
        }

        $date = $this->postingDate($order, $dto->postingDate);
        $good = Money::qty($dto->goodQuantity);
        $scrap = Money::qty($dto->scrapQuantity);
        $setup = Money::round($dto->setupMinutes);
        $run = Money::round($dto->runMinutes);
        $labor = Money::round($dto->laborMinutes ?? Money::add($setup, $run));
        $downtime = Money::round($dto->downtimeMinutes);

        foreach (['goodQuantity' => $good, 'scrapQuantity' => $scrap, 'setupMinutes' => $setup, 'runMinutes' => $run, 'laborMinutes' => $labor, 'downtimeMinutes' => $downtime] as $field => $value) {
            if (Money::isNegative($value)) {
                throw new ManufacturingOperationException("{$field} cannot be negative.", Str::snake($field));
            }
        }
        if (! Money::isPositive(Money::add($good, $scrap)) && ! Money::isPositive(Money::add($setup, $run)) && ! $dto->isFinal) {
            throw new ManufacturingOperationException('A confirmation needs a quantity or a time.', 'good_quantity');
        }

        $laborCost = Money::round(Money::mul(Money::div($labor, '60'), $op->labor_rate));
        $overheadCost = Money::round(Money::mul(Money::div(Money::add($setup, $run), '60'), $op->overhead_rate));

        $confirmation = MfgOperationConfirmation::create([
            'tenant_id' => $order->tenant_id,
            'confirmation_number' => 'CNF-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'production_order_id' => $order->id,
            'production_order_operation_id' => $op->id,
            'shop_floor_execution_id' => $dto->shopFloorExecutionId,
            'subcontract_order_id' => $subcontractOrderId,
            'setup_minutes' => $setup,
            'run_minutes' => $run,
            'labor_minutes' => $labor,
            'downtime_minutes' => $downtime,
            'good_quantity' => $good,
            'scrap_quantity' => $scrap,
            'scrap_reason' => $dto->scrapReason,
            'is_final' => $dto->isFinal,
            'labor_cost' => $laborCost,
            'overhead_cost' => $overheadCost,
            'subcontract_cost' => Money::round($subcontractCost),
            'posting_date' => $date,
            'confirmed_by' => $this->uuidOrNull($actorUserId),
            'confirmed_at' => now(),
        ]);

        $entry = $this->accounting->postConversion($order, $laborCost, $overheadCost, $date, (string) $confirmation->id, "Operation {$op->sequence} confirmed: {$order->order_number}", $actorUserId);
        $confirmation->update(['journal_entry_id' => $entry?->id]);

        $op->fill([
            'actual_setup_minutes' => Money::round(Money::add($op->actual_setup_minutes, $setup)),
            'actual_run_minutes' => Money::round(Money::add($op->actual_run_minutes, $run)),
            'actual_labor_minutes' => Money::round(Money::add($op->actual_labor_minutes, $labor)),
            'actual_labor_cost' => Money::round(Money::add($op->actual_labor_cost, $laborCost), 4),
            'actual_overhead_cost' => Money::round(Money::add($op->actual_overhead_cost, $overheadCost), 4),
            'completed_quantity' => Money::qty(Money::add($op->completed_quantity, $good)),
            'scrapped_quantity' => Money::qty(Money::add($op->scrapped_quantity, $scrap)),
            'status' => $dto->isFinal ? 'completed' : 'in_progress',
            'started_at' => $op->started_at ?? now(),
            'completed_at' => $dto->isFinal ? now() : null,
        ])->save();

        $order->actual_labor_cost = Money::round(Money::add($order->actual_labor_cost, $laborCost), 4);
        $order->actual_overhead_cost = Money::round(Money::add($order->actual_overhead_cost, $overheadCost), 4);
        $order->scrapped_quantity = Money::qty(Money::add($order->scrapped_quantity, $scrap));
        $order->save();
        $this->touchInProgress($order);

        $processed = Money::add($good, $scrap);
        if (Money::isPositive($processed) && $subcontractOrderId === null) {
            $this->backflush($order, $processed, static fn (MfgProductionOrderLine $line): bool => $line->operation_sequence === $op->sequence, $date, $actorUserId);
        }

        $this->dispatcher->publishAndDispatch(new OperationConfirmedEvent(
            confirmationId: (string) $confirmation->id,
            productionOrderId: (string) $order->id,
            productionOrderOperationId: (string) $op->id,
            goodQuantity: $good,
            scrapQuantity: $scrap,
            laborCost: $laborCost,
            overheadCost: $overheadCost,
            isFinal: $dto->isFinal,
            tenantId: (string) $order->tenant_id
        ));

        return $confirmation->refresh();
    }

    /**
     * The order locked for update, refused when it does not accept issues, confirmations or receipts.
     */
    public function lockOpen(MfgProductionOrder $order): MfgProductionOrder
    {
        $locked = $this->lock($order);
        if (! $locked->isOpen()) {
            throw new ManufacturingOperationException("Production order [{$locked->order_number}] is {$locked->status}: release it first, or it is already completed.", 'status');
        }

        return $locked;
    }

    /**
     * The document date of a posting, refused when no open fiscal period contains it.
     */
    public function postingDate(MfgProductionOrder $order, ?string $date): string
    {
        $day = CarbonImmutable::parse($date ?? now())->toDateString();
        $this->accounting->assertPeriodOpen((string) $order->tenant_id, $day);

        return $day;
    }

    /**
     * Consumes the backflushed components selected by the filter for a processed quantity.
     *
     * @param  callable(MfgProductionOrderLine): bool  $filter
     */
    private function backflush(MfgProductionOrder $order, string $processed, callable $filter, string $date, ?string $actorUserId): void
    {
        $lines = MfgProductionOrderLine::query()->where('production_order_id', $order->id)->where('issue_method', 'backflush')->orderBy('id')->lockForUpdate()->get();

        foreach ($lines->filter($filter) as $line) {
            $quantity = Money::qty(Money::mul($line->quantity_per_unit, $processed));
            if (Money::isPositive($quantity)) {
                $this->consume($order, $line, $quantity, null, MfgMaterialMovement::Backflush, $date, $actorUserId);
            }
        }
    }

    /**
     * Copies the routing operations to the order with planned times for the quantity and the work centre rates in
     * force, forward-scheduled on the work centre calendars from the planned start date.
     */
    private function copyOperations(MfgProductionOrder $order, MfgRouting $routing, string $quantity): void
    {
        $cursor = CarbonImmutable::parse($order->planned_start_date);
        $operations = MfgOperation::query()->with('workCenter')->where('routing_id', $routing->id)->orderBy('sequence')->get();

        foreach ($operations as $operation) {
            $workCenter = $operation->workCenter;
            if (! $workCenter instanceof MfgWorkCenter) {
                continue;
            }

            $run = Money::mul($operation->run_time_minutes_per_unit, $quantity);
            $planned = $operation->is_subcontracted ? '0' : Money::add($operation->setup_time_minutes, $run);
            $slot = $this->workCenters->schedule($workCenter, $cursor, $planned);

            MfgProductionOrderOperation::create([
                'tenant_id' => $order->tenant_id,
                'production_order_id' => $order->id,
                'operation_id' => $operation->id,
                'sequence' => $operation->sequence,
                'operation_code' => $operation->operation_code,
                'name' => $operation->name,
                'work_center_id' => $workCenter->id,
                'machine_center_id' => $operation->machine_center_id,
                'status' => 'pending',
                'planned_setup_minutes' => $operation->is_subcontracted ? '0' : Money::round($operation->setup_time_minutes),
                'planned_run_minutes' => $operation->is_subcontracted ? '0' : Money::round($run),
                'planned_labor_minutes' => $operation->is_subcontracted ? '0' : Money::round($operation->laborMinutesFor($quantity)),
                'labor_rate' => $workCenter->hourly_cost_rate,
                'overhead_rate' => $workCenter->hourly_overhead_rate,
                'is_subcontracted' => $operation->is_subcontracted,
                'subcontract_unit_cost' => $operation->subcontract_unit_cost,
                'scheduled_date' => $slot['start']->toDateString(),
            ]);

            $cursor = $operation->is_subcontracted ? $cursor : $slot['next'];
        }
    }

    /**
     * Releases every reservation still held by the order's lines.
     */
    private function releaseReservations(MfgProductionOrder $order): void
    {
        $lines = MfgProductionOrderLine::query()->where('production_order_id', $order->id)->whereNotNull('reservation_id')->get();
        foreach ($lines as $line) {
            $this->stock->releaseReservation($line->reservation_id);
            $line->update(['allocated_quantity' => '0', 'reservation_id' => null]);
        }
    }

    /**
     * Component lots consumed by the order with their quantities, for the genealogy of the output lot.
     *
     * @return array<string, string>
     */
    private function componentLots(MfgProductionOrder $order): array
    {
        return MfgMaterialMovement::query()
            ->where('production_order_id', $order->id)
            ->whereIn('movement_type', [MfgMaterialMovement::Issue, MfgMaterialMovement::Backflush, MfgMaterialMovement::SubcontractConsume])
            ->whereNotNull('lot_id')
            ->get()
            ->groupBy('lot_id')
            ->map(static fn ($rows): string => Money::qty($rows->reduce(static fn (string $sum, MfgMaterialMovement $row): string => Money::add($sum, $row->quantity), '0')))
            ->all();
    }

    /**
     * A released order moves to in progress with its first issue, confirmation or receipt.
     */
    private function touchInProgress(MfgProductionOrder $order): void
    {
        if ($order->status === 'released') {
            $order->transitionTo('in_progress');
            $order->save();
        }
    }

    /**
     * The order row locked for update.
     */
    private function lock(MfgProductionOrder $order): MfgProductionOrder
    {
        return MfgProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Accounting currency of the tenant.
     */
    private function currency(string $tenantId): string
    {
        return TenantAccountingCurrency::of($tenantId);
    }

    /**
     * The id when it is a UUID (user ids are), else null.
     */
    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
