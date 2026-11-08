<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Stock side of purchasing, on the single stock ledger: goods received from a supplier (into a receiving bin or, when
 * a quality inspection is due, into a quarantine bin), released from quarantine, returned to the supplier, and
 * additional costs (landed costs) capitalized into the moving average cost of the item. Every quantity moves through
 * the double-entry movement service.
 */
final readonly class SupplierReceiptStockService
{
    /** Zones whose stock is not available: never a destination of a plain receipt. */
    private const array BlockedZoneTypes = ['quarantine', 'transit'];

    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private StockValuationServiceInterface $valuation,
        private WarehouseLocationService $locations,
    ) {}

    /**
     * Books a received quantity. Lots are created on first receipt (quarantined when the goods go to quarantine);
     * serialized items need one serial per unit.
     *
     * @param  list<string>  $serialNumbers
     * @return array{movement_id: string, lot_id: ?string, warehouse_bin_id: string}
     */
    public function receive(StockItem $item, WarehouseBin $bin, string $quantity, string $unitCost, ?string $lotNumber, array $serialNumbers, string $receiptId, ?string $supplierId, bool $quarantine): array
    {
        return DB::transaction(function () use ($item, $bin, $quantity, $unitCost, $lotNumber, $serialNumbers, $receiptId, $supplierId, $quarantine): array {
            if ($item->tracking_type === 'lot' && ($lotNumber === null || $lotNumber === '')) {
                throw new StockRuleViolation("Item [{$item->sku}] is received by lot: give the lot number.");
            }

            if ($item->tracking_type === 'serial' && bccomp((string) count($serialNumbers), $quantity, 4) !== 0) {
                throw new StockRuleViolation("Item [{$item->sku}] is serialized: give one serial number per received unit.");
            }

            $lot = null;

            if ($lotNumber !== null && $lotNumber !== '') {
                $lot = StockLot::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $item->tenant_id, 'stock_item_id' => $item->id, 'lot_number' => $lotNumber],
                    ['company_id' => $item->company_id, 'supplier_id' => $supplierId, 'received_at' => now()->toDateString(), 'is_quarantined' => $quarantine]
                );

                if ($quarantine && ! $lot->is_quarantined) {
                    $lot->update(['is_quarantined' => true]);
                }
            }

            $this->valuation->computeItemMovingAverageCost($item, (float) $quantity, (float) $unitCost);

            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $item->id,
                sourceBinId: null,
                destinationBinId: (string) $bin->id,
                quantity: (float) $quantity,
                movementType: 'receipt',
                lotId: $lot?->id,
                originDocumentType: 'purchase_receipt',
                originDocumentId: $receiptId,
                customUnitCost: (float) $unitCost,
                notes: $quarantine ? 'Received into quarantine pending quality inspection' : null,
            ));

            foreach ($serialNumbers as $serial) {
                $exists = StockSerialNumber::withoutGlobalScopes()->where('tenant_id', $item->tenant_id)->where('stock_item_id', $item->id)->where('serial_number', $serial)->exists();

                if ($exists) {
                    throw new StockRuleViolation("Serial number [{$serial}] of item [{$item->sku}] already exists.");
                }

                StockSerialNumber::create([
                    'tenant_id' => $item->tenant_id,
                    'company_id' => $item->company_id,
                    'stock_item_id' => $item->id,
                    'serial_number' => $serial,
                    'status' => 'in_stock',
                    'current_warehouse_bin_id' => $bin->id,
                    'lot_id' => $lot?->id,
                ]);
            }

            return ['movement_id' => (string) $movement->id, 'lot_id' => $lot?->id !== null ? (string) $lot->id : null, 'warehouse_bin_id' => (string) $bin->id];
        });
    }

    /**
     * Moves inspected goods out of quarantine into the stock bin; the lot is released when nothing of it stays in a
     * quarantine bin.
     */
    public function releaseFromQuarantine(StockItem $item, WarehouseBin $quarantineBin, WarehouseBin $stockBin, string $quantity, ?string $lotId, string $receiptId): StockMovement
    {
        return DB::transaction(function () use ($item, $quarantineBin, $stockBin, $quantity, $lotId, $receiptId): StockMovement {
            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $item->id,
                sourceBinId: (string) $quarantineBin->id,
                destinationBinId: (string) $stockBin->id,
                quantity: (float) $quantity,
                movementType: 'internal_transfer',
                lotId: $lotId,
                originDocumentType: 'purchase_receipt_inspection',
                originDocumentId: $receiptId,
                notes: 'Released from quarantine after quality inspection',
            ));

            StockSerialNumber::withoutGlobalScopes()
                ->where('stock_item_id', $item->id)
                ->where('current_warehouse_bin_id', $quarantineBin->id)
                ->when($lotId !== null, static fn ($query) => $query->where('lot_id', $lotId))
                ->limit((int) $quantity)
                ->update(['current_warehouse_bin_id' => $stockBin->id]);

            if ($lotId !== null) {
                $stillQuarantined = DB::table('stock_quants')
                    ->where('lot_id', $lotId)
                    ->where('warehouse_bin_id', $quarantineBin->id)
                    ->whereNull('deleted_at')
                    ->where('quantity_on_hand', '>', 0)
                    ->exists();

                if (! $stillQuarantined) {
                    StockLot::withoutGlobalScopes()->whereKey($lotId)->update(['is_quarantined' => false]);
                }
            }

            return $movement;
        });
    }

    /**
     * Takes goods returned to the supplier out of the bin they sit in, at the unit cost they were received at.
     */
    public function returnToSupplier(StockItem $item, WarehouseBin $bin, string $quantity, string $unitCost, ?string $lotId, string $returnId): StockMovement
    {
        return $this->movements->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: (string) $bin->id,
            destinationBinId: null,
            quantity: (float) $quantity,
            movementType: 'supplier_return',
            lotId: $lotId,
            originDocumentType: 'purchase_return',
            originDocumentId: $returnId,
            customUnitCost: (float) $unitCost,
        ));
    }

    /**
     * Adds a cost (freight, duty...) to the value of the stock of the item: the moving average cost rises by the
     * amount spread on the quantity on hand. Returns the new moving average cost; nothing changes without stock.
     */
    public function capitalizeAdditionalCost(StockItem $item, string $amount): string
    {
        return DB::transaction(function () use ($item, $amount): string {
            /** @var StockItem $locked */
            $locked = StockItem::withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $onHand = number_format($locked->total_on_hand, 4, '.', '');

            if (bccomp($onHand, '0', 4) <= 0) {
                return (string) $locked->current_moving_average_cost;
            }

            $currentValue = bcmul((string) $locked->current_moving_average_cost, $onHand, 8);
            $newCost = bcdiv(bcadd($currentValue, $amount, 8), $onHand, 6);
            $rounded = bcadd($newCost, '0.00005', 4);
            $locked->update(['current_moving_average_cost' => $rounded]);

            return $rounded;
        });
    }

    /**
     * The receiving bin of the warehouse: a bin of a receiving zone, else of a storage zone, never quarantine or
     * transit; a default bin is created when the warehouse has none.
     */
    public function receivingBin(Warehouse $warehouse): WarehouseBin
    {
        $bins = static fn (array $zoneTypes, bool $exclude) => WarehouseBin::withoutGlobalScopes()
            ->whereNull('warehouse_bins.deleted_at')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->{$exclude ? 'whereNotIn' : 'whereIn'}('zone_type', $zoneTypes))
            ->orderBy('code')
            ->orderBy('id')
            ->first();

        return $bins(['receiving'], false) ?? $bins(self::BlockedZoneTypes, true) ?? $this->locations->addBin($warehouse, [
            'zone_code' => 'RECEIVING',
            'zone_name' => 'Receiving',
            'zone_type' => 'receiving',
            'aisle_code' => 'RCV',
            'rack_code' => 'RCV',
            'bin_code' => 'RCV',
            'barcode' => $warehouse->code.'-RCV-'.substr((string) $warehouse->id, -8),
        ]);
    }

    /**
     * The quarantine bin of the warehouse, created in a quarantine zone when missing.
     */
    public function quarantineBin(Warehouse $warehouse): WarehouseBin
    {
        $bin = WarehouseBin::withoutGlobalScopes()
            ->whereNull('warehouse_bins.deleted_at')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->where('zone_type', 'quarantine'))
            ->orderBy('code')
            ->first();

        return $bin ?? $this->locations->addBin($warehouse, [
            'zone_code' => 'QUARANTINE',
            'zone_name' => 'Quarantine',
            'zone_type' => 'quarantine',
            'aisle_code' => 'QA',
            'rack_code' => 'QA',
            'bin_code' => 'QA',
            'barcode' => $warehouse->code.'-QA-'.substr((string) $warehouse->id, -8),
        ]);
    }

    /**
     * The warehouse a receipt goes to: the one given, else the first active warehouse of the tenant.
     */
    public function warehouseFor(string $tenantId, ?string $warehouseId): ?Warehouse
    {
        $query = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId);

        if ($warehouseId !== null && $warehouseId !== '') {
            return $query->whereKey($warehouseId)->first();
        }

        return $query->where('is_active', true)->orderBy('created_at')->first();
    }
}
