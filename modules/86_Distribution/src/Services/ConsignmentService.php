<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Distribution\Domain\Enums\SettlementStatus;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Models\ConsignmentSettlement;
use Modules\Distribution\Models\ConsignmentSettlementLine;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockAllocationPlanner;
use Modules\Inventory\Support\Quantity;

/**
 * Consignment stock (conto deposito): stock of ours held at a customer or partner (consignment out), or stock of a third party held
 * at our premises (consignment in). Reported consumption issues the stock and records a settlement ready for invoicing; unsold
 * goods go back to a warehouse of ours through a transfer.
 */
final class ConsignmentService
{
    public function __construct(
        private readonly StockPositionService $positions,
        private readonly NetworkService $network,
        private readonly StockAllocationPlanner $planner,
        private readonly DoubleEntryMovementServiceInterface $movements,
        private readonly TransferWorkflowService $transfers,
    ) {}

    /**
     * Stock held at (or for) third parties with the days it has been there and its value.
     *
     * @param  'out'|'in'|null  $direction
     * @return array{rows: list<array<string, mixed>>, total_value: string}
     */
    public function stock(?string $direction = null): array
    {
        $roles = $direction === 'out' ? [WarehouseRole::ConsignmentOut] : ($direction === 'in' ? [WarehouseRole::ConsignmentIn] : [WarehouseRole::ConsignmentOut, WarehouseRole::ConsignmentIn]);
        $warehouses = Warehouse::query()->get()->filter(fn (Warehouse $warehouse): bool => in_array($this->network->roleOf($warehouse), $roles, true))->keyBy('id');

        if ($warehouses->isEmpty()) {
            return ['rows' => [], 'total_value' => '0.00'];
        }

        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', $warehouses->keys()->all())->get()->keyBy('warehouse_id');
        $positions = $this->positions->onHand($warehouses->keys()->map(static fn ($id): string => (string) $id)->all());
        $items = StockItem::query()->whereIn('id', array_map(static fn (string $key): string => explode('|', $key)[1], array_keys($positions)))->get()->keyBy('id');
        $since = StockMovement::query()
            ->whereIn('warehouse_id', $warehouses->keys()->all())
            ->whereNotNull('destination_location_id')
            ->groupBy('warehouse_id', 'stock_item_id')
            ->selectRaw('warehouse_id as wid, stock_item_id as iid, MIN(executed_at) as first_in')
            ->get()
            ->mapWithKeys(static fn ($row): array => [StockPositionService::key((string) $row->getAttribute('wid'), (string) $row->getAttribute('iid')) => Carbon::parse((string) $row->getAttribute('first_in'))]);

        $rows = [];
        $total = '0';

        foreach ($positions as $key => $position) {
            [$warehouseId, $itemId] = explode('|', $key);

            if (! Quantity::isPositive($position['on_hand']) || ! $warehouses->has($warehouseId) || ! $items->has($itemId)) {
                continue;
            }

            $warehouse = $warehouses->get($warehouseId);
            $item = $items->get($itemId);
            $cost = Quantity::of((string) $item->current_moving_average_cost);
            $value = Quantity::value($position['on_hand'], $cost);
            $days = isset($since[$key]) ? (int) $since[$key]->diffInDays(Carbon::now()) : 0;
            $max = $profiles->get($warehouseId)?->max_consignment_days;
            $total = Quantity::add($total, $value, 2);

            $rows[] = [
                'warehouse' => ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name],
                'stock_item' => ['id' => (string) $item->id, 'sku' => (string) $item->sku, 'name' => (string) $item->name, 'uom' => (string) $item->uom],
                'direction' => $this->network->roleOf($warehouse) === WarehouseRole::ConsignmentOut ? 'out' : 'in',
                'quantity' => $position['on_hand'],
                'unit_cost' => $cost,
                'value' => Quantity::of($value, 2),
                'days_held' => $days,
                'max_consignment_days' => $max,
                'is_overdue' => $max !== null && $days > $max,
            ];
        }

        return ['rows' => $rows, 'total_value' => Quantity::of($total, 2)];
    }

    /**
     * Records the consumption reported at a consignment warehouse: the stock leaves the warehouse and the settlement is ready for invoicing.
     *
     * @param  list<array{stock_item_id: string, quantity: string|int|float, unit_price?: string|int|float|null}>  $lines
     */
    public function settle(Warehouse $warehouse, array $lines, ?string $reference, ?string $settledAt, ?string $notes, ?string $userId): ConsignmentSettlement
    {
        $role = $this->network->roleOf($warehouse);

        if (! $role->isConsignment()) {
            throw new StockRuleViolation("Warehouse [{$warehouse->name}] is not a consignment warehouse.");
        }

        return DB::transaction(function () use ($warehouse, $lines, $reference, $settledAt, $notes, $userId, $role): ConsignmentSettlement {
            $settlement = ConsignmentSettlement::create([
                'settlement_number' => 'CS-'.Carbon::now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'warehouse_id' => $warehouse->id,
                'direction' => $role === WarehouseRole::ConsignmentOut ? 'out' : 'in',
                'status' => SettlementStatus::Open->value,
                'reference' => $reference,
                'settled_at' => $settledAt ?? Carbon::today()->toDateString(),
                'notes' => $notes,
            ]);

            $totalQuantity = '0';
            $totalValue = '0';

            foreach ($lines as $line) {
                $item = StockItem::query()->findOrFail($line['stock_item_id']);
                $quantity = Quantity::of($line['quantity']);

                if (! Quantity::isPositive($quantity)) {
                    throw new StockRuleViolation('The consumed quantity must be greater than zero.');
                }

                $shares = $this->planner->split($this->planner->lockEligibleQuants($item, $warehouse), $quantity, (string) $warehouse->name);

                foreach ($shares as $share) {
                    $this->movements->executeTransfer(new StockTransferDTO(
                        stockItemId: (string) $item->id,
                        sourceBinId: (string) $share['quant']->warehouse_bin_id,
                        destinationBinId: null,
                        quantity: (float) $share['quantity'],
                        movementType: 'consignment_consumption',
                        lotId: $share['quant']->lot_id,
                        originDocumentType: ConsignmentSettlement::class,
                        originDocumentId: (string) $settlement->id,
                        userId: $userId,
                        notes: "Consignment settlement {$settlement->settlement_number}"
                    ));
                }

                $price = Quantity::of($line['unit_price'] ?? $item->current_moving_average_cost);
                $lineTotal = Quantity::value($quantity, $price);
                $totalQuantity = Quantity::add($totalQuantity, $quantity);
                $totalValue = Quantity::add($totalValue, $lineTotal, 2);

                ConsignmentSettlementLine::create([
                    'settlement_id' => $settlement->id,
                    'stock_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'line_total' => Quantity::of($lineTotal, 2),
                ]);
            }

            $settlement->update(['total_quantity' => $totalQuantity, 'total_value' => Quantity::of($totalValue, 2)]);

            return $settlement->load('lines.item', 'warehouse');
        });
    }

    /**
     * Unsold goods back to a warehouse of ours: a draft transfer from the consignment warehouse.
     *
     * @param  list<array{stock_item_id: string, quantity: string|int|float}>  $lines
     */
    public function returnStock(Warehouse $consignment, Warehouse $destination, array $lines, ?string $notes, ?string $userId): TransferOrder
    {
        if ($this->network->roleOf($consignment) !== WarehouseRole::ConsignmentOut) {
            throw new StockRuleViolation("Warehouse [{$consignment->name}] does not hold our stock on consignment.");
        }

        return $this->transfers->create($consignment, $destination, $lines, $userId, null, $notes);
    }
}
