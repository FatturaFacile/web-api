<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Listeners;

use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\WarehouseBin;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Support\Amount;
use Modules\TimeTravel\Support\Instant;

/**
 * Warehouse inventory (domain 06): every stock movement (goods receipt, issue, transfer between warehouses, lot movement) with the cost it
 * was booked at, and the cost updates (revaluations, change of valuation method). Movements inside one warehouse change no stock and
 * are not recorded.
 */
final class InventoryEventCapture
{
    use CapturesDomainEvents;

    public function onStockTransferred(StockTransferredEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::InventoryWms, $event->movementId, ['InventoryLotMoved'])) {
            return;
        }

        $movement = StockMovement::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->movementId)->first();

        if ($movement === null) {
            return;
        }

        $quantity = Amount::of($movement->quantity);
        $value = Amount::of($movement->total_valuation);
        $legs = [];

        $source = $event->sourceBinId !== null ? $this->warehouseOf($event->sourceBinId) : null;
        $destination = $event->destinationBinId !== null ? $this->warehouseOf($event->destinationBinId) : null;

        if ($event->sourceBinId === null && $destination !== null) {
            $legs[] = ['warehouse_id' => $destination, 'quantity_delta' => $quantity, 'value_delta' => $value];
        } elseif ($event->destinationBinId === null && $source !== null) {
            $legs[] = ['warehouse_id' => $source, 'quantity_delta' => Amount::neg($quantity), 'value_delta' => Amount::neg($value)];
        } elseif ($source !== null && $destination !== null && $source !== $destination) {
            $legs[] = ['warehouse_id' => $source, 'quantity_delta' => Amount::neg($quantity), 'value_delta' => Amount::neg($value)];
            $legs[] = ['warehouse_id' => $destination, 'quantity_delta' => $quantity, 'value_delta' => $value];
        }

        if ($legs === []) {
            return;
        }

        $this->record(
            $event->tenantId,
            AggregateType::InventoryWms,
            $event->movementId,
            'InventoryLotMoved',
            Instant::from((string) $movement->executed_at),
            [
                'movement_id' => $event->movementId,
                'movement_type' => $event->movementType,
                'item_id' => $event->stockItemId,
                'lot_id' => $movement->lot_id !== null ? (string) $movement->lot_id : '',
                'unit_cost' => Amount::of($movement->unit_cost),
                'origin_document_type' => $movement->origin_document_type,
                'origin_document_id' => $movement->origin_document_id,
                'legs' => $legs,
            ],
            'inventory.stock.transferred',
            $movement->executed_by_user_id !== null ? (string) $movement->executed_by_user_id : null,
        );
    }

    public function onRevalued(InventoryRevaluedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::InventoryWms, $event->revaluationId, ['StandardCostUpdated'])) {
            return;
        }

        $revaluation = InventoryRevaluation::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->revaluationId)->first();
        $legs = [];
        $allocated = '0';

        foreach (is_array($revaluation?->lines) ? $revaluation->lines : [] as $line) {
            if (is_array($line) && isset($line['item_id'], $line['old_value'], $line['new_value'])) {
                $delta = Amount::sub($line['new_value'], $line['old_value']);
                $legs[] = ['item_id' => (string) $line['item_id'], 'warehouse_id' => '', 'quantity_delta' => '0', 'value_delta' => $delta];
                $allocated = Amount::add($allocated, $delta);
            }
        }

        // What the lines do not explain (landed cost, standard cost variances) is kept as an unallocated leg: the total always matches the books.
        $rest = Amount::sub($event->totalInventoryDelta, $allocated);

        if (! Amount::isZero($rest)) {
            $legs[] = ['item_id' => '', 'warehouse_id' => '', 'quantity_delta' => '0', 'value_delta' => $rest];
        }

        if ($legs === []) {
            return;
        }

        $this->record(
            $event->tenantId,
            AggregateType::InventoryWms,
            $event->revaluationId,
            'StandardCostUpdated',
            $this->validAtOf($event->postingDate),
            [
                'revaluation_id' => $event->revaluationId,
                'source_type' => $event->sourceType,
                'total_inventory_delta' => Amount::of($event->totalInventoryDelta),
                'item_id' => '',
                'lot_id' => '',
                'unit_cost' => '0',
                'legs' => $legs,
            ],
            'inventory.revalued',
        );
    }

    private function warehouseOf(string $binId): ?string
    {
        $bin = WarehouseBin::withoutGlobalScopes()->with('rack.aisle.zone')->whereKey($binId)->first();
        $warehouse = $bin?->rack?->aisle?->zone?->warehouse_id;

        return $warehouse !== null ? (string) $warehouse : null;
    }
}
