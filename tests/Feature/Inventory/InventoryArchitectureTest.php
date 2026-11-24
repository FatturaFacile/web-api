<?php

declare(strict_types=1);

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;

test('all inventory models extend BaseEnterpriseModel', function (): void {
    $models = [
        Warehouse::class,
        StockItem::class,
        StockQuant::class,
        StockMovement::class,
        StockReservation::class,
        InventoryAdjustment::class,
        InventoryCountSession::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('all inventory services are properly bound in the service container', function (): void {
    expect(app()->bound(DoubleEntryMovementServiceInterface::class))->toBeTrue();
    expect(app()->bound(StockReservationServiceInterface::class))->toBeTrue();
    expect(app()->bound(StockValuationServiceInterface::class))->toBeTrue();
    expect(app()->bound(InventoryReplenishmentServiceInterface::class))->toBeTrue();
    expect(app()->bound(BarcodeScannerServiceInterface::class))->toBeTrue();
    expect(app()->bound(InventoryCountServiceInterface::class))->toBeTrue();
});
