<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

/**
 * The in-transit location of a warehouse: stock shipped to it by a transfer order waits there until it is received.
 * It is a bin of a `transit` zone, so its stock is valued and counted but never reserved, picked or sold
 * (StockAllocationPlanner, ProductStockLedger). Created on the first transfer towards the warehouse, after making sure
 * the warehouse has its default bin, so the transit bin never becomes the default one.
 */
final readonly class TransitBinResolver
{
    public const string CODE = 'TRANSIT';

    public function __construct(
        private WarehouseLocationService $locations,
        private WarehouseDefaultBinResolver $defaultBins
    ) {}

    public function resolve(Warehouse $warehouse): WarehouseBin
    {
        $existing = $this->find($warehouse);

        if ($existing !== null) {
            return $existing;
        }

        $this->defaultBins->resolve((string) $warehouse->id);

        return DB::transaction(function () use ($warehouse): WarehouseBin {
            Warehouse::withoutGlobalScopes()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();

            $existing = $this->find($warehouse);

            if ($existing !== null) {
                return $existing;
            }

            $barcode = $warehouse->code.'-'.self::CODE;

            if (WarehouseBin::withoutGlobalScopes()->where('tenant_id', $warehouse->tenant_id)->where('barcode', $barcode)->exists()) {
                $barcode .= '-'.substr((string) $warehouse->id, -8);
            }

            return $this->locations->addBin($warehouse, [
                'zone_code' => self::CODE,
                'zone_name' => 'In transit',
                'zone_type' => 'transit',
                'aisle_code' => self::CODE,
                'rack_code' => self::CODE,
                'bin_code' => self::CODE,
                'barcode' => $barcode,
                'bin_type' => 'transit',
            ]);
        });
    }

    private function find(Warehouse $warehouse): ?WarehouseBin
    {
        return WarehouseBin::query()
            ->where('bin_type', 'transit')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id)->where('zone_type', 'transit'))
            ->orderBy('code')
            ->first();
    }
}
