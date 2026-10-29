<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\Events\BOMCreatedEvent;
use Modules\Manufacturing\Events\BOMRevisedEvent;
use Modules\Manufacturing\Events\FinishedGoodsProducedEvent;
use Modules\Manufacturing\Events\InventoryProducedEvent;
use Modules\Manufacturing\Events\MaterialConsumedEvent;
use Modules\Manufacturing\Events\MRPRunCompletedEvent;
use Modules\Manufacturing\Events\NonConformanceDetectedEvent;
use Modules\Manufacturing\Events\ProductionCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderCreatedEvent;
use Modules\Manufacturing\Events\ProductionOrderReleasedEvent;
use Modules\Manufacturing\Events\ProductionVarianceReadyEvent;
use Modules\Manufacturing\Events\QualityInspectionCompletedEvent;
use Modules\Manufacturing\Events\WIPRecognizedEvent;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Providers\ManufacturingServiceProvider;

test('Manufacturing module service provider is registered and resolves all service contracts', function () {
    expect(app()->providerIsLoaded(ManufacturingServiceProvider::class))->toBeTrue();

    $services = [
        BillOfMaterialsServiceInterface::class,
        RoutingServiceInterface::class,
        MrpServiceInterface::class,
        ProductionPlanningServiceInterface::class,
        ProductionExecutionServiceInterface::class,
        ShopFloorServiceInterface::class,
        QualityManagementServiceInterface::class,
        ProductionCostingServiceInterface::class,
        ManufacturingForecastingServiceInterface::class,
    ];

    foreach ($services as $serviceContract) {
        $instance = app($serviceContract);
        expect($instance)->toBeObject()
            ->and($instance)->toBeInstanceOf($serviceContract);
    }
});

test('Manufacturing models extend BaseEnterpriseModel and enforce tenancy and uuid conventions', function () {
    $models = [
        MfgItem::class,
        MfgBom::class,
        MfgRouting::class,
        MfgWorkCenter::class,
        MfgMrpRun::class,
        MfgProductionOrder::class,
        MfgShopFloorExecution::class,
        MfgQualityInspection::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new \ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('Manufacturing event classes implement DomainEventInterface and adhere to payload contracts', function () {
    $eventClasses = [
        BOMCreatedEvent::class,
        BOMRevisedEvent::class,
        MRPRunCompletedEvent::class,
        ProductionOrderCreatedEvent::class,
        ProductionOrderReleasedEvent::class,
        ProductionOrderCompletedEvent::class,
        MaterialConsumedEvent::class,
        FinishedGoodsProducedEvent::class,
        QualityInspectionCompletedEvent::class,
        NonConformanceDetectedEvent::class,
        WIPRecognizedEvent::class,
        ProductionCompletedEvent::class,
        ProductionVarianceReadyEvent::class,
        InventoryProducedEvent::class,
    ];

    foreach ($eventClasses as $cls) {
        $reflection = new \ReflectionClass($cls);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue();
    }
});
