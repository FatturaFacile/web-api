<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Inventory\Events\InventoryCountCompletedEvent;
use Modules\Inventory\Events\InventoryItemCreatedEvent;
use Modules\Inventory\Events\InventoryMovementCreatedEvent;
use Modules\Inventory\Events\InventoryRevaluationEvent;
use Modules\Inventory\Events\InventoryValuationCalculatedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Inventory\Events\LotExpiredEvent;
use Modules\Inventory\Events\ProjectInventoryIssueEvent;
use Modules\Inventory\Events\ProjectInventoryReservationEvent;
use Modules\Inventory\Events\ProjectMaterialConsumptionEvent;
use Modules\Inventory\Events\ReorderPointReachedEvent;
use Modules\Inventory\Events\SerialAssignedEvent;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Events\StockReceivedEvent;
use Modules\Inventory\Events\StockReleasedEvent;
use Modules\Inventory\Events\StockReservedEvent;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Listeners\AuditInventoryMovementListener;
use Modules\Inventory\Listeners\BlockAvailabilityOnLotExpiredListener;
use Modules\Inventory\Listeners\CheckReorderRulesListener;
use Modules\Inventory\Listeners\DispatchOutboxOnInventoryEventListener;
use Modules\Inventory\Listeners\ProcurementGoodsReceivedListener;
use Modules\Inventory\Listeners\ProcurementIntegrationListener;
use Modules\Inventory\Listeners\SendSafetyDataSheetsOnDeliveryListener;
use Modules\Inventory\Listeners\SettleStockShortfallsListener;
use Modules\Inventory\Listeners\UpdateSerialLifecycleListener;
use Modules\Inventory\Listeners\WorkflowApprovalInventoryListener;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Policies\InventoryAdjustmentPolicy;
use Modules\Inventory\Policies\InventoryCountSessionPolicy;
use Modules\Inventory\Policies\InventoryManagers;
use Modules\Inventory\Policies\StockItemPolicy;
use Modules\Inventory\Policies\StockMovementPolicy;
use Modules\Inventory\Policies\StockReservationPolicy;
use Modules\Inventory\Policies\WarehousePolicy;
use Modules\Inventory\Services\BarcodeScannerService;
use Modules\Inventory\Services\DoubleEntryMovementService;
use Modules\Inventory\Services\InventoryCountService;
use Modules\Inventory\Services\InventoryReplenishmentService;
use Modules\Inventory\Services\OutboundIssuePreference;
use Modules\Inventory\Services\StockReservationService;
use Modules\Inventory\Services\StockValuationService;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\InventoryAdjustmentRequestEvent;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;
use Modules\Procurement\Events\InventoryReservationRequestedEvent;
use Modules\Procurement\Events\SupplierReturnRequestedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DoubleEntryMovementServiceInterface::class, DoubleEntryMovementService::class);
        $this->app->singleton(DoubleEntryMovementService::class);

        $this->app->singleton(StockReservationServiceInterface::class, StockReservationService::class);
        $this->app->singleton(StockReservationService::class);

        $this->app->singleton(StockValuationServiceInterface::class, StockValuationService::class);
        $this->app->singleton(StockValuationService::class);

        $this->app->singleton(InventoryReplenishmentServiceInterface::class, InventoryReplenishmentService::class);
        $this->app->singleton(InventoryReplenishmentService::class);

        $this->app->singleton(BarcodeScannerServiceInterface::class, BarcodeScannerService::class);
        $this->app->singleton(BarcodeScannerService::class);

        $this->app->singleton(InventoryCountServiceInterface::class, InventoryCountService::class);
        $this->app->singleton(InventoryCountService::class);

        // Per request (or job): where the stock of a document without warehouse is taken from (shipment confirmation).
        $this->app->scoped(OutboundIssuePreference::class);
    }

    public function boot(): void
    {
        // Load API Routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        // Register Policies
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(StockItem::class, StockItemPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
        Gate::policy(StockReservation::class, StockReservationPolicy::class);
        Gate::policy(InventoryAdjustment::class, InventoryAdjustmentPolicy::class);
        Gate::policy(InventoryCountSession::class, InventoryCountSessionPolicy::class);
        // Manager decisions of the warehouse: putaway rules, ABC classification, cycle count plans.
        Gate::define('inventory.manage', static fn (User $user): bool => InventoryManagers::includes($user));

        // Register Outbox Relaying for Domain Events
        Event::listen(InventoryItemCreatedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryMovementCreatedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(StockReceivedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(StockTransferredEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(StockReservedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(StockReleasedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(StockAdjustedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryCountCompletedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(ReorderPointReachedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryValuationCalculatedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryValuationReadyEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryCostingReadyEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryAdjustmentPostedEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryWriteOffEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(InventoryRevaluationEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(ProjectMaterialConsumptionEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(ProjectInventoryReservationEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(ProjectInventoryIssueEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(LotExpiredEvent::class, DispatchOutboxOnInventoryEventListener::class);
        Event::listen(SerialAssignedEvent::class, DispatchOutboxOnInventoryEventListener::class);

        // Register Cross-Module Domain Event Listeners
        Event::listen(GoodsReceivedEvent::class, ProcurementGoodsReceivedListener::class);
        Event::listen(InventoryReceiptRequestedEvent::class, [ProcurementIntegrationListener::class, 'handleInventoryReceipt']);
        Event::listen(InventoryReservationRequestedEvent::class, [ProcurementIntegrationListener::class, 'handleInventoryReservation']);
        Event::listen(SupplierReturnRequestedEvent::class, [ProcurementIntegrationListener::class, 'handleSupplierReturn']);
        Event::listen(InventoryAdjustmentRequestEvent::class, [ProcurementIntegrationListener::class, 'handleInventoryAdjustment']);

        // Automations
        Event::listen(StockTransferredEvent::class, CheckReorderRulesListener::class);
        Event::listen(StockTransferredEvent::class, AuditInventoryMovementListener::class);
        // Synchronous: incoming stock settles what was sold beyond the stock inside the same transaction.
        Event::listen(StockTransferredEvent::class, SettleStockShortfallsListener::class);
        Event::listen(LotExpiredEvent::class, BlockAvailabilityOnLotExpiredListener::class);
        Event::listen(SerialAssignedEvent::class, UpdateSerialLifecycleListener::class);
        Event::listen(DeliveryNoteIssuedEvent::class, SendSafetyDataSheetsOnDeliveryListener::class);

        // Workflow Engine V2 Approvals
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalInventoryListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalInventoryListener::class, 'handleRejected']);
    }
}
