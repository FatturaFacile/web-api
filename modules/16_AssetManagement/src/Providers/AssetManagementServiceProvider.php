<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\Events\AssetAcquisitionReadyEvent;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetCapitalizationReadyEvent;
use Modules\AssetManagement\Events\AssetCapitalizedEvent;
use Modules\AssetManagement\Events\AssetCashImpactEvent;
use Modules\AssetManagement\Events\AssetDepreciationCalculatedEvent;
use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDepreciationReadyEvent;
use Modules\AssetManagement\Events\AssetDisposalCashForecastEvent;
use Modules\AssetManagement\Events\AssetDisposalForecastEvent;
use Modules\AssetManagement\Events\AssetDisposalReadyEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetImpairedEvent;
use Modules\AssetManagement\Events\AssetImpairmentReadyEvent;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\AssetPurchaseForecastEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\AssetManagement\Events\AssetReplacementForecastEvent;
use Modules\AssetManagement\Events\AssetRevaluationReadyEvent;
use Modules\AssetManagement\Events\AssetRevaluedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\AssetManagement\Events\MaintenanceWorkOrderCreatedEvent;
use Modules\AssetManagement\Events\ProjectAssetAssignedEvent;
use Modules\AssetManagement\Events\ProjectAssetReleasedEvent;
use Modules\AssetManagement\Listeners\AssetAcquisitionEventListener;
use Modules\AssetManagement\Listeners\DispatchOutboxOnAssetEventListener;
use Modules\AssetManagement\Listeners\GeneralLedgerAssetListener;
use Modules\AssetManagement\Listeners\ProjectAssetEventListener;
use Modules\AssetManagement\Listeners\WorkflowApprovalAssetListener;
use Modules\AssetManagement\Models\Asset;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Policies\AssetCategoryPolicy;
use Modules\AssetManagement\Policies\AssetDisposalPolicy;
use Modules\AssetManagement\Policies\DepreciationRunPolicy;
use Modules\AssetManagement\Policies\FixedAssetPolicy;
use Modules\AssetManagement\Policies\MaintenanceWorkOrderPolicy;
use Modules\AssetManagement\Services\AssetAccountingService;
use Modules\AssetManagement\Services\AssetLifecycleService;
use Modules\AssetManagement\Services\DepreciationEngineService;
use Modules\AssetManagement\Services\MaintenanceManagementService;
use Modules\AssetManagement\Services\ProjectAssetIntegrationService;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class AssetManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssetAccountingServiceInterface::class, AssetAccountingService::class);
        $this->app->singleton(DepreciationEngineServiceInterface::class, DepreciationEngineService::class);
        $this->app->singleton(AssetLifecycleServiceInterface::class, AssetLifecycleService::class);
        $this->app->singleton(MaintenanceManagementServiceInterface::class, MaintenanceManagementService::class);
        $this->app->singleton(ProjectAssetIntegrationServiceInterface::class, ProjectAssetIntegrationService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(FixedAsset::class, FixedAssetPolicy::class);
        Gate::policy(Asset::class, FixedAssetPolicy::class);
        Gate::policy(AssetCategory::class, AssetCategoryPolicy::class);
        Gate::policy(MaintenanceWorkOrder::class, MaintenanceWorkOrderPolicy::class);
        Gate::policy(DepreciationRun::class, DepreciationRunPolicy::class);
        Gate::policy(AssetDisposal::class, AssetDisposalPolicy::class);
    }

    private function registerEventListeners(): void
    {
        // 1. Outbox Relaying for Module 16 Domain Events (24 Events)
        $domainEvents = [
            AssetRegisteredEvent::class,
            AssetAssignedEvent::class,
            AssetAcquisitionReadyEvent::class,
            AssetCapitalizationReadyEvent::class,
            AssetCapitalizedEvent::class,
            AssetTransferredEvent::class,
            AssetRevaluedEvent::class,
            AssetImpairedEvent::class,
            AssetDisposedEvent::class,
            AssetMaintenanceCreatedEvent::class,
            MaintenanceWorkOrderCreatedEvent::class,
            MaintenanceCompletedEvent::class,
            ProjectAssetAssignedEvent::class,
            ProjectAssetReleasedEvent::class,
            AssetDepreciationCalculatedEvent::class,
            AssetDepreciationPostedEvent::class,
            AssetDepreciationReadyEvent::class,
            AssetImpairmentReadyEvent::class,
            AssetDisposalReadyEvent::class,
            AssetRevaluationReadyEvent::class,
            AssetPurchaseForecastEvent::class,
            AssetReplacementForecastEvent::class,
            AssetDisposalCashForecastEvent::class,
            AssetDisposalForecastEvent::class,
            AssetCashImpactEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnAssetEventListener::class);
        }

        // 2. Upstream Procurement & Inventory Acquisition Events
        Event::listen(PurchaseOrderCommittedEvent::class, [AssetAcquisitionEventListener::class, 'handlePurchaseOrderCommitted']);
        Event::listen(GoodsReceivedEvent::class, [AssetAcquisitionEventListener::class, 'handleGoodsReceived']);
        Event::listen(PurchaseLiabilityReadyEvent::class, [AssetAcquisitionEventListener::class, 'handlePurchaseLiabilityReady']);
        Event::listen(InventoryValuationReadyEvent::class, [AssetAcquisitionEventListener::class, 'handleInventoryValuationReady']);
        Event::listen(InventoryAdjustmentPostedEvent::class, [AssetAcquisitionEventListener::class, 'handleInventoryAdjustmentPosted']);
        Event::listen(InventoryWriteOffEvent::class, [AssetAcquisitionEventListener::class, 'handleInventoryWriteOff']);

        // 3. General Ledger Period & Year Closing Events
        Event::listen(JournalPostedEvent::class, [GeneralLedgerAssetListener::class, 'handleJournalPosted']);
        Event::listen(PeriodClosedEvent::class, [GeneralLedgerAssetListener::class, 'handlePeriodClosed']);
        Event::listen(YearClosedEvent::class, [GeneralLedgerAssetListener::class, 'handleYearClosed']);

        // 4. Projects Lifecycle Events
        Event::listen(ProjectCreatedEvent::class, [ProjectAssetEventListener::class, 'handleProjectCreated']);
        Event::listen(ProjectClosureReadyEvent::class, [ProjectAssetEventListener::class, 'handleProjectClosureReady']);

        // 5. Workflow Engine V2 Approvals
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalAssetListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalAssetListener::class, 'handleWorkflowRejected']);
    }
}
