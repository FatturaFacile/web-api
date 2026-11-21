<?php

declare(strict_types=1);

namespace Modules\Procurement\Providers;

use App\Application\Procurement\Rfq\Services\RfqScoreSynchronizer;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\BudgetAvailabilityGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\CatalogItemResolverInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ProcurementConversionGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\RequisitionNotificationGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition as Mod044PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Services\DelegationThresholdDomainService;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use App\Domain\Procurement\Rfq\Contracts\ExchangeRateResolverInterface;
use App\Domain\Procurement\Rfq\Contracts\PurchaseOrderAwardCreatorInterface;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Contracts\SupplierRatingResolverInterface;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Services\BidScoringDomainService;
use App\Infrastructure\Procurement\PurchaseRequisition\Services\CatalogItemResolverAdapter;
use App\Infrastructure\Procurement\PurchaseRequisition\Services\Mod006ApprovalWorkflowAdapter;
use App\Infrastructure\Procurement\PurchaseRequisition\Services\Mod019BudgetAvailabilityAdapter;
use App\Infrastructure\Procurement\PurchaseRequisition\Services\Mod045Mod046ConversionAdapter;
use App\Infrastructure\Procurement\PurchaseRequisition\Services\RequisitionNotificationAdapter;
use App\Infrastructure\Procurement\Rfq\Services\CreatePurchaseOrderFromAwardAction;
use App\Infrastructure\Procurement\Rfq\Services\ExchangeRateResolver;
use App\Infrastructure\Procurement\Rfq\Services\Mod044RequisitionAdapter;
use App\Infrastructure\Procurement\Rfq\Services\RfqAttachmentStorageService;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Infrastructure\Procurement\Rfq\Services\SupplierScorecardRatingResolver;
use App\Models\PassiveInvoice;
use App\Policies\Mod044PurchaseRequisitionPolicy;
use App\Policies\RfqPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Procurement\Contracts\InventoryReceivingBridgeInterface;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\Contracts\PurchaseBudgetCommitmentInterface;
use Modules\Procurement\Contracts\PurchaseSuggestionHandOffInterface;
use Modules\Procurement\Contracts\SupplierEvaluationServiceInterface;
use Modules\Procurement\Contracts\ThreeWayMatchingServiceInterface;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\InventoryAdjustmentRequestEvent;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;
use Modules\Procurement\Events\InventoryReservationRequestedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Events\PurchaseRequisitionCreatedEvent;
use Modules\Procurement\Events\PurchaseReturnCreatedEvent;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Events\SupplierContractExpiredEvent;
use Modules\Procurement\Events\SupplierCreatedEvent;
use Modules\Procurement\Events\SupplierReturnRequestedEvent;
use Modules\Procurement\Events\SupplierSuspendedEvent;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Events\ThreeWayMatchPassedEvent;
use Modules\Procurement\Listeners\AuthorizeReceivingOnPurchaseOrderApprovedListener;
use Modules\Procurement\Listeners\AutoGeneratePoOnRequisitionApprovedListener;
use Modules\Procurement\Listeners\BlockPurchasingOnExpiredContractListener;
use Modules\Procurement\Listeners\CommitBudgetOnPurchaseOrderApprovedListener;
use Modules\Procurement\Listeners\DispatchOutboxOnProcurementEventListener;
use Modules\Procurement\Listeners\EnforceSupplierSuspensionListener;
use Modules\Procurement\Listeners\EscalateWorkflowOnThreeWayMatchFailedListener;
use Modules\Procurement\Listeners\UpdateAvlOnSupplierApprovedListener;
use Modules\Procurement\Listeners\WorkflowApprovalProcurementListener;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Policies\PurchaseOrderPolicy;
use Modules\Procurement\Policies\PurchaseReceiptPolicy;
use Modules\Procurement\Policies\PurchaseRequisitionPolicy;
use Modules\Procurement\Policies\SupplierContractPolicy;
use Modules\Procurement\Policies\SupplierPolicy;
use Modules\Procurement\Services\InventoryReceivingBridgeService;
use Modules\Procurement\Services\PassiveInvoiceApprovalService;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Services\ProcurementService;
use Modules\Procurement\Services\ProcurementSettingsService;
use Modules\Procurement\Services\PurchaseBudgetCommitmentService;
use Modules\Procurement\Services\PurchaseSuggestionService;
use Modules\Procurement\Services\SupplierEvaluationService;
use Modules\Procurement\Services\ThreeWayMatchingService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Wires the procurement module: services, policies, routes, outbox relaying of its domain events and the listeners
 * that connect it with workflow, budget and inventory.
 */
final class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcurementServiceInterface::class, ProcurementService::class);
        $this->app->singleton(ProcurementService::class);

        $this->app->singleton(ThreeWayMatchingServiceInterface::class, ThreeWayMatchingService::class);
        $this->app->singleton(ThreeWayMatchingService::class);

        $this->app->singleton(SupplierEvaluationServiceInterface::class, SupplierEvaluationService::class);
        $this->app->singleton(SupplierEvaluationService::class);

        $this->app->singleton(PurchaseBudgetCommitmentInterface::class, PurchaseBudgetCommitmentService::class);
        $this->app->singleton(PurchaseBudgetCommitmentService::class);

        $this->app->singleton(InventoryReceivingBridgeInterface::class, InventoryReceivingBridgeService::class);
        $this->app->singleton(InventoryReceivingBridgeService::class);

        $this->app->singleton(PurchaseSuggestionHandOffInterface::class, PurchaseSuggestionService::class);
        $this->app->singleton(ProcurementAuditLedgerService::class);
        $this->app->singleton(ProcurementSettingsService::class);

        // Mod 045 RFQ Clean Architecture & CQRS bindings
        $this->app->singleton(BidScoringDomainService::class);
        $this->app->singleton(RfqScoreSynchronizer::class);
        $this->app->singleton(RfqAttachmentStorageService::class);
        $this->app->singleton(RfqSupplierRecipientResolver::class);
        $this->app->singleton(RequisitionSourcingAdapterInterface::class, Mod044RequisitionAdapter::class);
        $this->app->singleton(PurchaseOrderAwardCreatorInterface::class, CreatePurchaseOrderFromAwardAction::class);
        $this->app->singleton(SupplierRatingResolverInterface::class, SupplierScorecardRatingResolver::class);
        $this->app->singleton(ExchangeRateResolverInterface::class, ExchangeRateResolver::class);

        // Mod 044 Purchase Requisition Clean Architecture & CQRS bindings
        $this->app->singleton(RequisitionTotalsDomainService::class);
        $this->app->singleton(DelegationThresholdDomainService::class);
        $this->app->singleton(BudgetAvailabilityGatewayInterface::class, Mod019BudgetAvailabilityAdapter::class);
        $this->app->singleton(ApprovalWorkflowGatewayInterface::class, Mod006ApprovalWorkflowAdapter::class);
        $this->app->singleton(ProcurementConversionGatewayInterface::class, Mod045Mod046ConversionAdapter::class);
        $this->app->singleton(RequisitionNotificationGatewayInterface::class, RequisitionNotificationAdapter::class);
        $this->app->singleton(CatalogItemResolverInterface::class, CatalogItemResolverAdapter::class);
    }

    public function boot(): void
    {
        // Load API Routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        PassiveInvoice::creating(function (PassiveInvoice $invoice): void {
            $this->app->make(PassiveInvoiceApprovalService::class)->flagNewInvoice($invoice);
        });

        // Register Policies
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseRequisition::class, PurchaseRequisitionPolicy::class);
        Gate::policy(Mod044PurchaseRequisition::class, Mod044PurchaseRequisitionPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(PurchaseReceipt::class, PurchaseReceiptPolicy::class);
        Gate::policy(SupplierContract::class, SupplierContractPolicy::class);
        Gate::policy(RequestForQuotation::class, RfqPolicy::class);
        Gate::policy(Rfq::class, RfqPolicy::class);

        // Register Outbox Relaying for Domain Events
        Event::listen(SupplierCreatedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(SupplierApprovedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(SupplierSuspendedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(SupplierContractExpiredEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseRequisitionCreatedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseRequisitionApprovedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseOrderCreatedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseOrderApprovedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseOrderCancelledEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseOrderCommittedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(GoodsReceivedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(InventoryReceiptRequestedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(InventoryReservationRequestedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(InventoryAdjustmentRequestEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseReturnCreatedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(SupplierReturnRequestedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(ThreeWayMatchPassedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(ThreeWayMatchDiscrepancyDetectedEvent::class, DispatchOutboxOnProcurementEventListener::class);
        Event::listen(PurchaseLiabilityReadyEvent::class, DispatchOutboxOnProcurementEventListener::class);

        // Process automations. Approval workflows start on submission (ProcurementApprovalService), not on creation.
        Event::listen(PurchaseOrderApprovedEvent::class, CommitBudgetOnPurchaseOrderApprovedListener::class);
        Event::listen(PurchaseOrderApprovedEvent::class, AuthorizeReceivingOnPurchaseOrderApprovedListener::class);
        Event::listen(PurchaseRequisitionApprovedEvent::class, AutoGeneratePoOnRequisitionApprovedListener::class);
        Event::listen(SupplierApprovedEvent::class, UpdateAvlOnSupplierApprovedListener::class);
        Event::listen(SupplierSuspendedEvent::class, EnforceSupplierSuspensionListener::class);
        Event::listen(SupplierContractExpiredEvent::class, BlockPurchasingOnExpiredContractListener::class);
        Event::listen(ThreeWayMatchDiscrepancyDetectedEvent::class, EscalateWorkflowOnThreeWayMatchFailedListener::class);

        // Synchronize Workflow Approvals/Rejections with Module 21
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalProcurementListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalProcurementListener::class, 'handleRejected']);
    }
}
