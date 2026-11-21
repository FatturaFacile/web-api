<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\Inventory\Models\StockLot;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Events\MesMaterialLotConsumedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Commands\ConsumeMaterialLotCommand;
use Modules\Manufacturing\Mes\ValueObjects\LotCode;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgMesMaterialConsumption;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Services\ManufacturingStockService;
use Modules\Manufacturing\Services\ProductionExecutionService;
use Modules\Manufacturing\Support\Money;

/**
 * Registers a component lot consumed on a phase (Italian: materia prima utilizzata): the lot must exist for the item,
 * must not be quarantined and must hold the stock; the issue goes through the order's material movements, so lot
 * genealogy of the finished goods follows.
 */
final class ConsumeMaterialLotHandler extends PhaseCommandHandler
{
    public function __construct(
        EventDispatcher $dispatcher,
        private readonly ProductionExecutionService $execution,
        private readonly ManufacturingStockService $stock,
    ) {
        parent::__construct($dispatcher);
    }

    /**
     * Handles the command and returns the phase.
     */
    public function handle(ConsumeMaterialLotCommand $command): MfgMesPhase
    {
        $quantity = Money::qty($command->quantity);
        if (! Money::isPositive($quantity)) {
            throw new ManufacturingOperationException('The consumed quantity must be positive.', 'quantity');
        }
        $lotCode = $command->lotCode !== null && trim($command->lotCode) !== '' ? LotCode::fromString($command->lotCode) : null;

        return $this->execute($command, function () use ($command, $quantity, $lotCode): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::ConsumeMaterial, $phase);
            $at = $this->effectiveAt($command, $phase);

            $order = $this->execution->lockOpen(MfgProductionOrder::query()->where('tenant_id', $command->tenantId)->findOrFail($phase->production_order_id));
            $line = $this->componentLine($phase, $command->mfgItemId);
            $item = MfgItem::query()->where('tenant_id', $command->tenantId)->findOrFail($line->mfg_item_id);
            $lot = $this->resolveLot($item, $lotCode);

            $remaining = Money::sub($line->required_quantity, $line->consumed_quantity);
            if (Money::compare($quantity, $remaining) > 0 && ! $command->overConsumptionConfirmed) {
                $open = Money::qty(Money::max('0', $remaining));
                throw new ManufacturingOperationException("The order requires only {$open} more of [{$item->item_code}]: confirm the over-consumption.", 'quantity');
            }

            $movement = $this->execution->consume(
                $order,
                $line,
                $quantity,
                null,
                MfgMaterialMovement::Issue,
                $this->execution->postingDate($order, null),
                $command->actorUserId,
                $lot?->id,
            );

            $consumption = MfgMesMaterialConsumption::create([
                'tenant_id' => $command->tenantId,
                'phase_id' => $phase->id,
                'production_order_line_id' => $line->id,
                'mfg_item_id' => $item->id,
                'lot_id' => $lot?->id,
                'lot_code' => $lotCode?->toString(),
                'quantity' => $quantity,
                'material_movement_id' => $movement->id,
                'operator_id' => $phase->operator_id,
                'idempotency_key' => $command->idempotencyKey,
                'consumed_at' => $at,
            ]);

            $this->save($phase);
            $this->dispatcher->publishAndDispatch(new MesMaterialLotConsumedEvent(
                phaseId: (string) $phase->id,
                consumptionId: (string) $consumption->id,
                productionOrderId: (string) $order->id,
                mfgItemId: (string) $item->id,
                lotCode: $lotCode?->toString(),
                quantity: $quantity,
                tenantId: $command->tenantId,
            ));

            return $phase;
        });
    }

    /**
     * The order line of the component, preferring the line of the phase's own operation.
     */
    private function componentLine(MfgMesPhase $phase, string $mfgItemId): MfgProductionOrderLine
    {
        $sequence = MfgProductionOrderOperation::query()->whereKey($phase->production_order_operation_id)->value('sequence');
        $lines = MfgProductionOrderLine::query()
            ->where('production_order_id', $phase->production_order_id)
            ->where('mfg_item_id', $mfgItemId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $line = $lines->first(static fn (MfgProductionOrderLine $candidate): bool => $candidate->operation_sequence === $sequence) ?? $lines->first();
        if (! $line instanceof MfgProductionOrderLine) {
            throw new ManufacturingOperationException('The item is not a component of this production order.', 'mfg_item_id');
        }

        return $line;
    }

    /**
     * The stock lot of the scanned code: required for lot-tracked items, refused for untracked ones, and never a
     * quarantined lot.
     */
    private function resolveLot(MfgItem $item, ?LotCode $code): ?StockLot
    {
        $stockItem = $this->stock->stockItemFor($item);
        if ($stockItem->tracking_type !== 'lot') {
            if ($code instanceof LotCode) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is not lot-tracked.", 'lot_code');
            }

            return null;
        }
        if (! $code instanceof LotCode) {
            throw new ManufacturingOperationException("Item [{$item->item_code}] is lot-tracked: scan or enter its lot.", 'lot_code');
        }

        $lot = StockLot::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('stock_item_id', $stockItem->id)
            ->whereRaw('LOWER(lot_number) = ?', [strtolower($code->toString())])
            ->first();
        if (! $lot instanceof StockLot) {
            throw new ManufacturingOperationException("Lot [{$code->toString()}] does not exist for item [{$item->item_code}].", 'lot_code');
        }
        if ($lot->is_quarantined) {
            throw new ManufacturingOperationException("Lot [{$code->toString()}] is quarantined.", 'lot_code');
        }

        return $lot;
    }
}
