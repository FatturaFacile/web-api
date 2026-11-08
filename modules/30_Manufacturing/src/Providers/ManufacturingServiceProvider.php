<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
use Modules\Manufacturing\Events\EngineeringChangeImplementedEvent;
use Modules\Manufacturing\Events\FinishedGoodsProducedEvent;
use Modules\Manufacturing\Events\InventoryForecastEvent;
use Modules\Manufacturing\Events\InventoryProducedEvent;
use Modules\Manufacturing\Events\MaterialConsumedEvent;
use Modules\Manufacturing\Events\MaterialPurchaseForecastEvent;
use Modules\Manufacturing\Events\MRPRunCompletedEvent;
use Modules\Manufacturing\Events\NonConformanceDetectedEvent;
use Modules\Manufacturing\Events\OperationConfirmedEvent;
use Modules\Manufacturing\Events\ProductionCashForecastEvent;
use Modules\Manufacturing\Events\ProductionCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderClosedEvent;
use Modules\Manufacturing\Events\ProductionOrderCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderCreatedEvent;
use Modules\Manufacturing\Events\ProductionOrderReleasedEvent;
use Modules\Manufacturing\Events\ProductionVarianceReadyEvent;
use Modules\Manufacturing\Events\QualityInspectionCompletedEvent;
use Modules\Manufacturing\Events\StandardCostChangedEvent;
use Modules\Manufacturing\Events\SubcontractOrderReceivedEvent;
use Modules\Manufacturing\Events\WIPRecognizedEvent;
use Modules\Manufacturing\Listeners\DispatchOutboxOnManufacturingEventListener;
use Modules\Manufacturing\Listeners\WorkflowApprovalManufacturingListener;
use Modules\Manufacturing\Models\MfgAccountingSetting;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Policies\MfgAccountingSettingPolicy;
use Modules\Manufacturing\Policies\MfgBomPolicy;
use Modules\Manufacturing\Policies\MfgEngineeringChangeOrderPolicy;
use Modules\Manufacturing\Policies\MfgProductionOrderPolicy;
use Modules\Manufacturing\Services\BillOfMaterialsService;
use Modules\Manufacturing\Services\ManufacturingForecastingService;
use Modules\Manufacturing\Services\MrpService;
use Modules\Manufacturing\Services\ProductionCostingService;
use Modules\Manufacturing\Services\ProductionExecutionService;
use Modules\Manufacturing\Services\ProductionPlanningService;
use Modules\Manufacturing\Services\QualityManagementService;
use Modules\Manufacturing\Services\RoutingService;
use Modules\Manufacturing\Services\ShopFloorService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Registers the services, policies, routes and event listeners of the manufacturing module.
 */
final class ManufacturingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        MfgBom::class => MfgBomPolicy::class,
        MfgEngineeringChangeOrder::class => MfgEngineeringChangeOrderPolicy::class,
        MfgProductionOrder::class => MfgProductionOrderPolicy::class,
        MfgAccountingSetting::class => MfgAccountingSettingPolicy::class,
    ];

    /**
     * Events recorded in the transactional outbox.
     *
     * @var list<class-string>
     */
    private const array OutboxEvents = [
        BOMCreatedEvent::class,
        BOMRevisedEvent::class,
        EngineeringChangeImplementedEvent::class,
        MRPRunCompletedEvent::class,
        ProductionOrderCreatedEvent::class,
        ProductionOrderReleasedEvent::class,
        ProductionOrderCompletedEvent::class,
        ProductionOrderClosedEvent::class,
        OperationConfirmedEvent::class,
        MaterialConsumedEvent::class,
        FinishedGoodsProducedEvent::class,
        SubcontractOrderReceivedEvent::class,
        QualityInspectionCompletedEvent::class,
        NonConformanceDetectedEvent::class,
        WIPRecognizedEvent::class,
        ProductionCompletedEvent::class,
        ProductionVarianceReadyEvent::class,
        InventoryProducedEvent::class,
        StandardCostChangedEvent::class,
        ProductionCashForecastEvent::class,
        MaterialPurchaseForecastEvent::class,
        InventoryForecastEvent::class,
    ];

    /**
     * Binds the service contracts.
     */
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

    /**
     * Registers policies, routes and listeners.
     */
    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $routesPath = base_path('modules/30_Manufacturing/routes/api.php');
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalManufacturingListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalManufacturingListener::class, 'handleRejected']);

        foreach (self::OutboxEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnManufacturingEventListener::class);
        }
    }
}
