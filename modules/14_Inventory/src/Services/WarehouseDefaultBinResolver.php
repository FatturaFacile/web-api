<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

/**
 * The bins of a warehouse and its default bin, where stock without a location of its own is put.
 *
 * The app and the inventory module share the `warehouses` table, but stock lives in bins: documents that only name a
 * warehouse (till sales, delivery notes, purchase receipts of the app) book into its default bin. That is the bin with
 * the lowest code, the same bin the module's goods receipts use, so both sides fill the same place. A warehouse without
 * bins gets a `DEFAULT` location (zone, aisle, rack and bin) on its first movement.
 */
final readonly class WarehouseDefaultBinResolver
{
    public const string DEFAULT_CODE = 'DEFAULT';

    public function __construct(private WarehouseLocationService $locations) {}

    /**
     * The default bin, created when the warehouse has none. The warehouse row is locked before creating, so two
     * concurrent first movements create one bin.
     */
    public function resolve(string $warehouseId): WarehouseBin
    {
        $bin = $this->bins($warehouseId)->first();

        if ($bin !== null) {
            return $bin;
        }

        return DB::transaction(function () use ($warehouseId): WarehouseBin {
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->lockForUpdate()->firstOrFail();

            $bin = $this->bins($warehouseId)->first();

            if ($bin !== null) {
                return $bin;
            }

            $barcode = $warehouse->code.'-'.self::DEFAULT_CODE;

            if (WarehouseBin::withoutGlobalScopes()->where('tenant_id', $warehouse->tenant_id)->where('barcode', $barcode)->exists()) {
                $barcode .= '-'.substr((string) $warehouse->id, -8);
            }

            return $this->locations->addBin($warehouse, [
                'zone_code' => self::DEFAULT_CODE,
                'zone_name' => 'Default',
                'zone_type' => 'storage',
                'aisle_code' => self::DEFAULT_CODE,
                'rack_code' => self::DEFAULT_CODE,
                'bin_code' => self::DEFAULT_CODE,
                'barcode' => $barcode,
            ]);
        });
    }

    /**
     * Live bins of the warehouse, default bin first (lowest code, then oldest).
     *
     * @return Collection<int, WarehouseBin>
     */
    public function bins(string $warehouseId): Collection
    {
        return WarehouseBin::withoutGlobalScopes()
            ->whereNull('warehouse_bins.deleted_at')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->withoutGlobalScopes()->where('warehouse_id', $warehouseId))
            ->orderBy('code')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
