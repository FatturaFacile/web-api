<?php

declare(strict_types=1);

namespace Modules\Treasury\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Projects\Events\ProjectBillingReadyEvent;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Projects\Events\ProjectCollectionForecastEvent;
use Modules\Projects\Events\ProjectPaymentForecastEvent;
use Modules\Projects\Events\ProjectReceivableReadyEvent;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\BankReconciliationCompletedEvent;
use Modules\Treasury\Events\BankStatementImportedEvent;
use Modules\Treasury\Events\CashForecastGeneratedEvent;
use Modules\Treasury\Events\CashPositionUpdatedEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Events\FXAdjustmentCalculatedEvent;
use Modules\Treasury\Events\LiquidityAlertEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Events\PaymentScheduledEvent;
use Modules\Treasury\Events\TreasuryAllocationExecutedEvent;
use Modules\Treasury\Listeners\DispatchOutboxOnTreasuryEventListener;
use Modules\Treasury\Listeners\GeneralLedgerTreasuryListener;
use Modules\Treasury\Listeners\ProcurementTreasuryListener;
use Modules\Treasury\Listeners\ProjectsTreasuryListener;
use Modules\Treasury\Listeners\TreasuryReadinessEventListener;
use Modules\Treasury\Listeners\WorkflowApprovalTreasuryListener;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Policies\BankAccountPolicy;
use Modules\Treasury\Policies\BankReconciliationPolicy;
use Modules\Treasury\Policies\CashForecastPolicy;
use Modules\Treasury\Policies\PaymentBatchPolicy;
use Modules\Treasury\Services\BankReconciliationService;
use Modules\Treasury\Services\CashForecastService;
use Modules\Treasury\Services\ForeignExchangeRiskService;
use Modules\Treasury\Services\LiquidityManagementService;
use Modules\Treasury\Services\PaymentFactoryService;
use Modules\Treasury\Services\ReceivableCollectionService;
use Modules\Treasury\Services\TreasuryPositionService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TreasuryPositionServiceInterface::class, TreasuryPositionService::class);
        $this->app->singleton(CashForecastServiceInterface::class, CashForecastService::class);
        $this->app->singleton(PaymentFactoryServiceInterface::class, PaymentFactoryService::class);
        $this->app->singleton(ReceivableCollectionServiceInterface::class, ReceivableCollectionService::class);
        $this->app->singleton(BankReconciliationServiceInterface::class, BankReconciliationService::class);
        $this->app->singleton(LiquidityManagementServiceInterface::class, LiquidityManagementService::class);
        $this->app->singleton(ForeignExchangeRiskServiceInterface::class, ForeignExchangeRiskService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventConsumers();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(PaymentBatch::class, PaymentBatchPolicy::class);
        Gate::policy(BankReconciliation::class, BankReconciliationPolicy::class);
        Gate::policy(CashForecast::class, CashForecastPolicy::class);
    }

    private function registerEventConsumers(): void
    {
        // 1. Mandatory Module 22 Treasury Readiness Events
        Event::listen(PaymentReadyEvent::class, [TreasuryReadinessEventListener::class, 'handlePaymentReady']);
        Event::listen(ReceivableReadyEvent::class, [TreasuryReadinessEventListener::class, 'handleReceivableReady']);
        Event::listen(PayableReadyEvent::class, [TreasuryReadinessEventListener::class, 'handlePayableReady']);
        Event::listen(CashForecastReadyEvent::class, [TreasuryReadinessEventListener::class, 'handleCashForecastReady']);
        Event::listen(BankPostingReadyEvent::class, [TreasuryReadinessEventListener::class, 'handleBankPostingReady']);
        Event::listen(TreasuryAllocationReadyEvent::class, [TreasuryReadinessEventListener::class, 'handleTreasuryAllocationReady']);

        // 2. Module 22 General Ledger Lifecycle Events
        Event::listen(JournalPostedEvent::class, [GeneralLedgerTreasuryListener::class, 'handleJournalPosted']);
        Event::listen(JournalReversedEvent::class, [GeneralLedgerTreasuryListener::class, 'handleJournalReversed']);
        Event::listen(PeriodClosedEvent::class, [GeneralLedgerTreasuryListener::class, 'handlePeriodClosed']);
        Event::listen(PeriodOpenedEvent::class, [GeneralLedgerTreasuryListener::class, 'handlePeriodOpened']);
        Event::listen(YearClosedEvent::class, [GeneralLedgerTreasuryListener::class, 'handleYearClosed']);
        Event::listen(TrialBalanceGeneratedEvent::class, [GeneralLedgerTreasuryListener::class, 'handleTrialBalanceGenerated']);
        Event::listen(FinancialStatementGeneratedEvent::class, [GeneralLedgerTreasuryListener::class, 'handleFinancialStatementGenerated']);
        Event::listen(AccountReconciledEvent::class, [GeneralLedgerTreasuryListener::class, 'handleAccountReconciled']);

        // 3. Module 13 Procurement Events
        Event::listen(PurchaseLiabilityReadyEvent::class, [ProcurementTreasuryListener::class, 'handlePurchaseLiabilityReady']);

        // 4. Module 15 Projects Events
        Event::listen(ProjectCashFlowForecastEvent::class, [ProjectsTreasuryListener::class, 'handleCashFlowForecast']);
        Event::listen(ProjectBillingReadyEvent::class, [ProjectsTreasuryListener::class, 'handleBillingReady']);
        Event::listen(ProjectCollectionForecastEvent::class, [ProjectsTreasuryListener::class, 'handleCollectionForecast']);
        Event::listen(ProjectPaymentForecastEvent::class, [ProjectsTreasuryListener::class, 'handlePaymentForecast']);
        Event::listen(ProjectReceivableReadyEvent::class, [ProjectsTreasuryListener::class, 'handleReceivableReady']);

        // 5. Module 21 Workflow Engine Events
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalTreasuryListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalTreasuryListener::class, 'handleWorkflowRejected']);

        // 6. Treasury Domain Events -> Transactional Outbox
        Event::listen(PaymentScheduledEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(PaymentExecutedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(CollectionReceivedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(BankStatementImportedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(BankReconciliationCompletedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(CashForecastGeneratedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(LiquidityAlertEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(TreasuryAllocationExecutedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(CashPositionUpdatedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
        Event::listen(FXAdjustmentCalculatedEvent::class, DispatchOutboxOnTreasuryEventListener::class);
    }
}
