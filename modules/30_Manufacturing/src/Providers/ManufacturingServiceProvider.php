<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
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
use Modules\Manufacturing\Listeners\DispatchOutboxOnManufacturingEventListener;
use Modules\Manufacturing\Listeners\InventoryValuationManufacturingListener;
use Modules\Manufacturing\Listeners\ProcurementGoodsReceivedManufacturingListener;
use Modules\Manufacturing\Listeners\WorkflowApprovalManufacturingListener;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Policies\MfgBomPolicy;
use Modules\Manufacturing\Policies\MfgMrpPolicy;
use Modules\Manufacturing\Policies\MfgProductionOrderPolicy;
use Modules\Manufacturing\Policies\MfgQualityPolicy;
use Modules\Manufacturing\Policies\MfgShopFloorPolicy;
use Modules\Manufacturing\Services\BillOfMaterialsService;
use Modules\Manufacturing\Services\ManufacturingForecastingService;
use Modules\Manufacturing\Services\MrpService;
use Modules\Manufacturing\Services\ProductionCostingService;
use Modules\Manufacturing\Services\ProductionExecutionService;
use Modules\Manufacturing\Services\ProductionPlanningService;
use Modules\Manufacturing\Services\QualityManagementService;
use Modules\Manufacturing\Services\RoutingService;
use Modules\Manufacturing\Services\ShopFloorService;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

final class ManufacturingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        MfgBom::class => MfgBomPolicy::class,
        MfgProductionOrder::class => MfgProductionOrderPolicy::class,
        MfgMrpRun::class => MfgMrpPolicy::class,
        MfgQualityInspection::class => MfgQualityPolicy::class,
        MfgShopFloorExecution::class => MfgShopFloorPolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(BillOfMaterialsServiceInterface::class, BillOfMaterialsService::class);
        $this->app->singleton(RoutingServiceInterface::class, RoutingService::class);
        $this->app->singleton(MrpServiceInterface::class, MrpService::class);
        $this->app->singleton(ProductionPlanningServiceInterface::class, ProductionPlanningService::class);
        $this->app->singleton(ProductionExecutionServiceInterface::class, ProductionExecutionService::class);
        $this->app->singleton(ShopFloorServiceInterface::class, ShopFloorService::class);
        $this->app->singleton(QualityManagementServiceInterface::class, QualityManagementService::class);
        $this->app->singleton(ProductionCostingServiceInterface::class, ProductionCostingService::class);
        $this->app->singleton(ManufacturingForecastingServiceInterface::class, ManufacturingForecastingService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->loadRoutes();
        $this->registerEventListeners();
    }

    private function registerPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    private function loadRoutes(): void
    {
        $routesPath = base_path('modules/30_Manufacturing/routes/api.php');
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }

    private function registerEventListeners(): void
    {
        // Cross-module inbound listeners
        Event::listen(GoodsReceivedEvent::class, ProcurementGoodsReceivedManufacturingListener::class);
        Event::listen(InventoryAdjustmentPostedEvent::class, InventoryValuationManufacturingListener::class);
        Event::listen(WorkflowInstanceCompletedEvent::class, WorkflowApprovalManufacturingListener::class);

        // Outbox dispatchers
        $manufacturingEvents = [
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

        foreach ($manufacturingEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnManufacturingEventListener::class);
        }
    }
}
