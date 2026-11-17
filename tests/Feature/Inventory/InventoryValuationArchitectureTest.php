<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\InventoryLedgerGatewayInterface;
use Modules\Inventory\Contracts\LandedCostAllocationServiceInterface;
use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\LandedCostAppliedEvent;
use Modules\Inventory\Events\PurchasePriceVarianceRecordedEvent;
use Modules\Inventory\Models\InventoryBookExport;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryCostLayerConsumption;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Models\LandedCostDocumentReceipt;

arch('inventory does not import general ledger or procurement models and services')
    ->expect('Modules\Inventory')
    ->not->toUse([
        'Modules\GeneralLedger\Models',
        'Modules\GeneralLedger\Services',
        'Modules\GeneralLedger\Listeners',
        'Modules\Procurement\Models',
        'Modules\Procurement\Services',
    ]);

arch('inventory valuation classes use strict types')
    ->expect([
        'Modules\Inventory\Services',
        'Modules\Inventory\Actions',
        'Modules\Inventory\Support',
        'Modules\Inventory\Enums',
    ])
    ->toUseStrictTypes();

arch('inventory valuation events are domain events')
    ->expect([LandedCostAppliedEvent::class, InventoryRevaluedEvent::class, PurchasePriceVarianceRecordedEvent::class])
    ->toImplement(DomainEventInterface::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('inventory valuation contracts are interfaces')
    ->expect([InventoryCostingServiceInterface::class, LandedCostAllocationServiceInterface::class, InventoryLedgerGatewayInterface::class])
    ->toBeInterfaces();

arch('inventory valuation models extend BaseEnterpriseModel')
    ->expect([
        InventoryCostLayer::class,
        InventoryCostLayerConsumption::class,
        LandedCostDocument::class,
        LandedCostDocumentReceipt::class,
        LandedCostAllocation::class,
        InventoryRevaluation::class,
        InventoryBookExport::class,
    ])
    ->toExtend(BaseEnterpriseModel::class);

test('valuation services are bound in the container', function (): void {
    expect(app()->bound(InventoryCostingServiceInterface::class))->toBeTrue()
        ->and(app()->bound(LandedCostAllocationServiceInterface::class))->toBeTrue()
        ->and(app(InventoryLedgerGatewayInterface::class))->toBeInstanceOf(InventoryLedgerGatewayInterface::class);
});
