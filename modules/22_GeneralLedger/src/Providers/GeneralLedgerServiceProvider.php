<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\GeneralLedger\Contracts\AccountingReconciliationServiceInterface;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Events\AccountReconciledEvent;
use Modules\GeneralLedger\Events\BankPostingReadyEvent;
use Modules\GeneralLedger\Events\CashForecastReadyEvent;
use Modules\GeneralLedger\Events\FinancialStatementGeneratedEvent;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\JournalReversedEvent;
use Modules\GeneralLedger\Events\PayableReadyEvent;
use Modules\GeneralLedger\Events\PaymentReadyEvent;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\PeriodOpenedEvent;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Events\TreasuryAllocationReadyEvent;
use Modules\GeneralLedger\Events\TrialBalanceGeneratedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;
use Modules\GeneralLedger\Listeners\DispatchOutboxOnGeneralLedgerEventListener;
use Modules\GeneralLedger\Listeners\InventoryAccountingListener;
use Modules\GeneralLedger\Listeners\ProcurementAccountingListener;
use Modules\GeneralLedger\Listeners\ProjectsAccountingListener;
use Modules\GeneralLedger\Listeners\WorkflowApprovalGeneralLedgerListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\ReconciliationRun;
use Modules\GeneralLedger\Policies\ChartOfAccountPolicy;
use Modules\GeneralLedger\Policies\FiscalPeriodPolicy;
use Modules\GeneralLedger\Policies\FiscalYearPolicy;
use Modules\GeneralLedger\Policies\JournalEntryPolicy;
use Modules\GeneralLedger\Policies\ReconciliationPolicy;
use Modules\GeneralLedger\Services\AccountingReconciliationService;
use Modules\GeneralLedger\Services\DoubleEntryPostingService;
use Modules\GeneralLedger\Services\FinancialStatementService;
use Modules\GeneralLedger\Services\PeriodClosingService;
use Modules\GeneralLedger\Services\TaxEngineReadinessService;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Inventory\Events\InventoryRevaluationEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Projects\Events\ProjectAccrualReadyEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectExpensePostingReadyEvent;
use Modules\Projects\Events\ProjectLabourCostReadyEvent;
use Modules\Projects\Events\ProjectMarginCalculatedEvent;
use Modules\Projects\Events\ProjectMaterialCostReadyEvent;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;
use Modules\Projects\Events\ProjectWipReadyEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class GeneralLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DoubleEntryPostingServiceInterface::class, DoubleEntryPostingService::class);
        $this->app->singleton(FinancialStatementServiceInterface::class, FinancialStatementService::class);
        $this->app->singleton(PeriodClosingServiceInterface::class, PeriodClosingService::class);
        $this->app->singleton(AccountingReconciliationServiceInterface::class, AccountingReconciliationService::class);
        $this->app->singleton(TaxEngineReadinessServiceInterface::class, TaxEngineReadinessService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(ChartOfAccount::class, ChartOfAccountPolicy::class);
        Gate::policy(FiscalYear::class, FiscalYearPolicy::class);
        Gate::policy(FiscalPeriod::class, FiscalPeriodPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(ReconciliationRun::class, ReconciliationPolicy::class);
    }

    private function registerEventListeners(): void
    {
        // Outbox Relaying for General Ledger Domain & Treasury Readiness Events
        $domainEvents = [
            JournalPostedEvent::class,
            JournalReversedEvent::class,
            PeriodClosedEvent::class,
            PeriodOpenedEvent::class,
            YearClosedEvent::class,
            TrialBalanceGeneratedEvent::class,
            FinancialStatementGeneratedEvent::class,
            AccountReconciledEvent::class,
            PaymentReadyEvent::class,
            ReceivableReadyEvent::class,
            PayableReadyEvent::class,
            CashForecastReadyEvent::class,
            BankPostingReadyEvent::class,
            TreasuryAllocationReadyEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnGeneralLedgerEventListener::class);
        }

        // Upstream Procurement Event Consumers
        Event::listen(PurchaseOrderCommittedEvent::class, [ProcurementAccountingListener::class, 'handlePurchaseOrderCommitted']);
        Event::listen(PurchaseOrderCancelledEvent::class, [ProcurementAccountingListener::class, 'handlePurchaseOrderCancelled']);
        Event::listen(GoodsReceivedEvent::class, [ProcurementAccountingListener::class, 'handleGoodsReceived']);
        Event::listen(PurchaseLiabilityReadyEvent::class, [ProcurementAccountingListener::class, 'handlePurchaseLiabilityReady']);

        // Upstream Inventory Event Consumers
        Event::listen(InventoryValuationReadyEvent::class, [InventoryAccountingListener::class, 'handleValuationReady']);
        Event::listen(InventoryCostingReadyEvent::class, [InventoryAccountingListener::class, 'handleCostingReady']);
        Event::listen(InventoryAdjustmentPostedEvent::class, [InventoryAccountingListener::class, 'handleAdjustmentPosted']);
        Event::listen(InventoryWriteOffEvent::class, [InventoryAccountingListener::class, 'handleWriteOff']);
        Event::listen(InventoryRevaluationEvent::class, [InventoryAccountingListener::class, 'handleRevaluation']);

        // Upstream Projects Event Consumers
        Event::listen(ProjectCostReadyEvent::class, [ProjectsAccountingListener::class, 'handleCostReady']);
        Event::listen(ProjectRevenueReadyEvent::class, [ProjectsAccountingListener::class, 'handleRevenueReady']);
        Event::listen(ProjectWipReadyEvent::class, [ProjectsAccountingListener::class, 'handleWipReady']);
        Event::listen(ProjectAccrualReadyEvent::class, [ProjectsAccountingListener::class, 'handleAccrualReady']);
        Event::listen(ProjectMarginCalculatedEvent::class, [ProjectsAccountingListener::class, 'handleMarginCalculated']);
        Event::listen(ProjectClosureReadyEvent::class, [ProjectsAccountingListener::class, 'handleClosureReady']);
        Event::listen(ProjectRevenueRecognitionReadyEvent::class, [ProjectsAccountingListener::class, 'handleRevenueRecognitionReady']);
        Event::listen(ProjectExpensePostingReadyEvent::class, [ProjectsAccountingListener::class, 'handleExpensePostingReady']);
        Event::listen(ProjectLabourCostReadyEvent::class, [ProjectsAccountingListener::class, 'handleLabourCostReady']);
        Event::listen(ProjectMaterialCostReadyEvent::class, [ProjectsAccountingListener::class, 'handleMaterialCostReady']);

        // Workflow Engine V2 Approvals
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalGeneralLedgerListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalGeneralLedgerListener::class, 'handleRejected']);
    }
}
