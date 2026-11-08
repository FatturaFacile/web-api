<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\LotTraceabilityService;
use Modules\Inventory\Services\StockAllocationPlanner;
use Modules\Inventory\Services\WarehouseDefaultBinResolver;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * The manufacturing module's only door to stock: every quantity moves through the inventory module's public services
 * (double-entry movements, reservations, allocation planner, default bins, lot genealogy), so the inventory ledger
 * stays the single stock truth. Production issues never go below zero: missing stock is refused with a 422.
 */
final readonly class ManufacturingStockService
{
    /** Origin document type written on the inventory movements and reservations of production orders. */
    public const string OriginType = 'mfg_production_order';

    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private StockReservationServiceInterface $reservations,
        private StockAllocationPlanner $planner,
        private WarehouseDefaultBinResolver $bins,
        private LotTraceabilityService $traceability,
    ) {}

    /**
     * The inventory stock item of a manufacturing item, refused when the item is not linked to one.
     */
    public function stockItemFor(MfgItem $item): StockItem
    {
        $stockItem = $item->stock_item_id !== null
            ? StockItem::withoutGlobalScopes()->where('tenant_id', $item->tenant_id)->whereNull('deleted_at')->find($item->stock_item_id)
            : null;

        if (! $stockItem instanceof StockItem) {
            throw new ManufacturingOperationException("Item [{$item->item_code}] is not linked to an inventory stock item, so its stock cannot move.", 'stock_item_id');
        }

        return $stockItem;
    }

    /**
     * The warehouse to use: the one given, else the default warehouse of the item, else the primary warehouse of the
     * tenant.
     */
    public function warehouseFor(string $tenantId, ?string $warehouseId, ?MfgItem $item = null): Warehouse
    {
        $warehouseId ??= $item?->default_warehouse_id;
        $query = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at');
        $warehouse = $warehouseId !== null
            ? $query->find($warehouseId)
            : $query->orderByDesc('is_primary')->orderBy('created_at')->first();

        if (! $warehouse instanceof Warehouse) {
            throw new ManufacturingOperationException('No warehouse of the company is available for the stock movement.', 'warehouse_id');
        }

        return $warehouse;
    }

    /**
     * Quantity of the item available (on hand less reserved) in the warehouse, optionally of one lot.
     */
    public function available(StockItem $stockItem, Warehouse $warehouse, ?string $lotId = null): string
    {
        return $this->planner->lockEligibleQuants($stockItem, $warehouse, $lotId)
            ->reduce(static fn (string $sum, StockQuant $quant): string => Money::add($sum, StockAllocationPlanner::available($quant)), '0');
    }

    /**
     * Reserves up to the quantity for a production order line; returns the reservation and the quantity it covers
     * (less than asked when the warehouse holds less, nothing when it holds none).
     *
     * @return array{reservation: StockReservation|null, quantity: string}
     */
    public function reserve(MfgItem $item, Warehouse $warehouse, string $quantity, string $lineId, ?string $actorUserId): array
    {
        $stockItem = $this->stockItemFor($item);
        $covered = Money::qty(Money::min($quantity, $this->available($stockItem, $warehouse)));

        if (! Money::isPositive($covered)) {
            return ['reservation' => null, 'quantity' => '0.0000'];
        }

        $reservation = $this->reservations->allocate(new StockReservationDTO(
            stockItemId: (string) $stockItem->id,
            warehouseId: (string) $warehouse->id,
            reservedForType: 'mfg_production_order_line',
            reservedForId: $lineId,
            quantity: Money::qtyToFloat($covered),
            expiresAt: now()->addYear()->toDateTimeString(),
            userId: $actorUserId,
        ));

        return ['reservation' => $reservation, 'quantity' => $covered];
    }

    /**
     * Releases a reservation of a production order line, if still active.
     */
    public function releaseReservation(?string $reservationId): void
    {
        if ($reservationId === null) {
            return;
        }

        $reservation = StockReservation::query()->find($reservationId);
        if ($reservation instanceof StockReservation) {
            $this->reservations->release($reservation);
        }
    }

    /**
     * Takes the quantity out of the warehouse, quant by quant in the inventory's picking order, refusing when the
     * available stock does not cover it. Returns the movements and their total valuation (the actual cost issued).
     *
     * @param  array{reference_id: string, movement_type: string, notes?: string|null, executed_at?: string|null, user_id?: string|null}  $context
     * @return array{movements: list<StockMovement>, total_cost: string, lots: array<string, string>}
     */
    public function issue(MfgItem $item, Warehouse $warehouse, string $quantity, ?string $lotId, array $context): array
    {
        return $this->moveOut($item, $warehouse, $quantity, $lotId, null, $context);
    }

    /**
     * Transfers the quantity from one warehouse to the default bin of another (components sent to a subcontractor and
     * back), with the same picking and the same refusal of missing stock as an issue.
     *
     * @param  array{reference_id: string, movement_type: string, notes?: string|null, executed_at?: string|null, user_id?: string|null}  $context
     * @return array{movements: list<StockMovement>, total_cost: string, lots: array<string, string>}
     */
    public function transfer(MfgItem $item, Warehouse $from, Warehouse $to, string $quantity, array $context): array
    {
        if ((string) $from->id === (string) $to->id) {
            throw new ManufacturingOperationException('The source and the destination warehouse must differ.', 'subcontract_warehouse_id');
        }

        return $this->moveOut($item, $from, $quantity, null, (string) $this->bins->resolve((string) $to->id)->id, $context);
    }

    /**
     * Books the output of a production order into the default bin of the warehouse at the given unit cost: one
     * movement for untracked or lot-tracked output, one per serial number for serialized output.
     *
     * @param  list<string>  $serialNumbers
     * @param  array{reference_id: string, movement_type: string, notes?: string|null, executed_at?: string|null, user_id?: string|null}  $context
     * @return array{movements: list<StockMovement>, lot: StockLot|null, serial_ids: list<string>}
     */
    public function receive(MfgItem $item, Warehouse $warehouse, string $quantity, string $unitCost, ?string $lotNumber, array $serialNumbers, array $context): array
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $unitCost, $lotNumber, $serialNumbers, $context): array {
            $stockItem = $this->stockItemFor($item);
            $bin = $this->bins->resolve((string) $warehouse->id);
            $tracking = (string) ($stockItem->tracking_type ?? 'none');

            if ($tracking === 'serial' && Money::compare(count($serialNumbers), $quantity) !== 0) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is serialized: give one serial number per unit received.", 'serial_numbers');
            }
            if ($tracking === 'lot' && ($lotNumber === null || $lotNumber === '')) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is lot-tracked: give the lot number of the output.", 'lot_number');
            }

            $lot = $lotNumber !== null && $lotNumber !== '' ? $this->lot($stockItem, $lotNumber, $context['executed_at'] ?? null) : null;
            $cost = Money::isPositive($unitCost) ? (float) Money::round($unitCost, 4) : null;
            $movements = [];
            $serialIds = [];

            if ($serialNumbers !== []) {
                foreach ($serialNumbers as $serialNumber) {
                    $serial = $this->serial($stockItem, $serialNumber, (string) $bin->id, $lot?->id);
                    $serialIds[] = (string) $serial->id;
                    $movements[] = $this->movements->executeTransfer($this->dto($stockItem, null, (string) $bin->id, 1.0, $lot?->id, $serial->id, $cost, $context));
                }
            } else {
                $movements[] = $this->movements->executeTransfer($this->dto($stockItem, null, (string) $bin->id, Money::qtyToFloat($quantity), $lot?->id, null, $cost, $context));
            }

            return ['movements' => $movements, 'lot' => $lot, 'serial_ids' => $serialIds];
        });
    }

    /**
     * Records in the lot genealogy that the output lot was made from the component lots, on the production order.
     *
     * @param  array<string, string>  $componentLots  component lot id => quantity consumed
     */
    public function linkLots(StockLot $outputLot, array $componentLots, string $productionOrderId): void
    {
        foreach ($componentLots as $componentLotId => $quantity) {
            $component = StockLot::query()->find($componentLotId);
            if (! $component instanceof StockLot || (string) $component->id === (string) $outputLot->id) {
                continue;
            }

            $this->traceability->link($outputLot, $component, ['quantity_consumed' => $quantity, 'production_order_id' => $productionOrderId]);
        }
    }

    /**
     * @param  array{reference_id: string, movement_type: string, notes?: string|null, executed_at?: string|null, user_id?: string|null}  $context
     * @return array{movements: list<StockMovement>, total_cost: string, lots: array<string, string>}
     */
    private function moveOut(MfgItem $item, Warehouse $warehouse, string $quantity, ?string $lotId, ?string $destinationBinId, array $context): array
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $lotId, $destinationBinId, $context): array {
            $stockItem = $this->stockItemFor($item);
            $quants = $this->planner->lockEligibleQuants($stockItem, $warehouse, $lotId);
            ['shares' => $shares, 'short' => $short] = $this->planner->take($quants, Money::qty($quantity));

            if (Money::isPositive($short)) {
                $missing = Money::qty($short);
                throw new ManufacturingOperationException("Not enough stock of [{$item->item_code}] in warehouse [{$warehouse->name}]: short by {$missing}.", 'quantity');
            }

            $movements = [];
            $total = '0';
            $lots = [];

            /** @var Collection<int, array{quant: StockQuant, quantity: string}> $legs */
            $legs = collect($shares);
            foreach ($legs as $share) {
                $quant = $share['quant'];

                try {
                    $movement = $this->movements->executeTransfer($this->dto($stockItem, (string) $quant->warehouse_bin_id, $destinationBinId, Money::qtyToFloat($share['quantity']), $quant->lot_id, null, null, $context));
                } catch (StockRuleViolation $violation) {
                    throw new ManufacturingOperationException($violation->getMessage(), 'quantity');
                }

                $movements[] = $movement;
                $total = Money::add($total, (string) $movement->total_valuation);
                if ($quant->lot_id !== null) {
                    $lots[(string) $quant->lot_id] = Money::qty(Money::add($lots[(string) $quant->lot_id] ?? '0', $share['quantity']));
                }
            }

            return ['movements' => $movements, 'total_cost' => Money::round($total), 'lots' => $lots];
        });
    }

    /**
     * @param  array{reference_id: string, movement_type: string, notes?: string|null, executed_at?: string|null, user_id?: string|null}  $context
     */
    private function dto(StockItem $stockItem, ?string $sourceBinId, ?string $destinationBinId, float $quantity, ?string $lotId, ?string $serialId, ?float $unitCost, array $context): StockTransferDTO
    {
        return new StockTransferDTO(
            stockItemId: (string) $stockItem->id,
            sourceBinId: $sourceBinId,
            destinationBinId: $destinationBinId,
            quantity: $quantity,
            movementType: $context['movement_type'],
            lotId: $lotId,
            serialNumberId: $serialId,
            originDocumentType: self::OriginType,
            originDocumentId: $context['reference_id'],
            userId: $context['user_id'] ?? null,
            customUnitCost: $unitCost,
            notes: $context['notes'] ?? null,
            executedAt: $context['executed_at'] ?? null,
        );
    }

    /**
     * The lot of the stock item with the number, created on the first receipt of the output.
     */
    private function lot(StockItem $stockItem, string $lotNumber, ?string $date): StockLot
    {
        /** @var StockLot $lot */
        $lot = StockLot::query()->firstOrCreate(
            ['tenant_id' => $stockItem->tenant_id, 'stock_item_id' => $stockItem->id, 'lot_number' => $lotNumber],
            ['company_id' => $stockItem->company_id, 'manufacturing_date' => $date ?? now()->toDateString(), 'received_at' => $date ?? now()->toDateString(), 'is_quarantined' => false]
        );

        return $lot;
    }

    /**
     * A new serial number of the stock item, refused when the number already exists for the item.
     */
    private function serial(StockItem $stockItem, string $serialNumber, string $binId, ?string $lotId): StockSerialNumber
    {
        $exists = StockSerialNumber::query()->where('stock_item_id', $stockItem->id)->where('serial_number', $serialNumber)->exists();
        if ($exists) {
            throw new ManufacturingOperationException("Serial number [{$serialNumber}] already exists for this item.", 'serial_numbers');
        }

        /** @var StockSerialNumber $serial */
        $serial = StockSerialNumber::query()->create([
            'tenant_id' => $stockItem->tenant_id,
            'company_id' => $stockItem->company_id,
            'stock_item_id' => $stockItem->id,
            'serial_number' => $serialNumber,
            'status' => 'in_stock',
            'current_warehouse_bin_id' => $binId,
            'lot_id' => $lotId,
        ]);

        return $serial;
    }
}
