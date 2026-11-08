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
use Modules\AssetManagement\Listeners\AssetAcquisitionEventListener;
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

/**
 * Wires the asset management module: service contracts, policies, listeners of the other modules and routes.
 */
final class AssetManagementServiceProvider extends ServiceProvider
{
    /**
     * Binds the service contracts.
     */
    public function register(): void
    {
        $this->app->singleton(AssetAccountingServiceInterface::class, AssetAccountingService::class);
        $this->app->singleton(DepreciationEngineServiceInterface::class, DepreciationEngineService::class);
        $this->app->singleton(AssetLifecycleServiceInterface::class, AssetLifecycleService::class);
        $this->app->singleton(MaintenanceManagementServiceInterface::class, MaintenanceManagementService::class);
        $this->app->singleton(ProjectAssetIntegrationServiceInterface::class, ProjectAssetIntegrationService::class);
    }

    /**
     * Registers policies, listeners and routes.
     */
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
        // Module events reach the outbox once, through EventDispatcher::publishAndDispatch in the services; no listener
        // records them a second time.

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
