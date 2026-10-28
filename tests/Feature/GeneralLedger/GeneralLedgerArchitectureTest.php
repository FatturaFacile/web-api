<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
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
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalBatch;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\AccountingReconciliationService;
use Modules\GeneralLedger\Services\DoubleEntryPostingService;
use Modules\GeneralLedger\Services\FinancialStatementService;
use Modules\GeneralLedger\Services\PeriodClosingService;
use Modules\GeneralLedger\Services\TaxEngineReadinessService;

test('all general ledger models inherit from BaseEnterpriseModel', function () {
    $models = [
        FiscalYear::class,
        FiscalPeriod::class,
        ChartOfAccount::class,
        JournalBatch::class,
        JournalEntry::class,
        JournalLine::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('all general ledger domain and treasury events implement DomainEventInterface', function () {
    $events = [
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

    foreach ($events as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue();
    }
});

test('all general ledger service interfaces are bound to concrete implementations', function () {
    expect(app(DoubleEntryPostingServiceInterface::class))->toBeInstanceOf(DoubleEntryPostingService::class)
        ->and(app(FinancialStatementServiceInterface::class))->toBeInstanceOf(FinancialStatementService::class)
        ->and(app(PeriodClosingServiceInterface::class))->toBeInstanceOf(PeriodClosingService::class)
        ->and(app(AccountingReconciliationServiceInterface::class))->toBeInstanceOf(AccountingReconciliationService::class)
        ->and(app(TaxEngineReadinessServiceInterface::class))->toBeInstanceOf(TaxEngineReadinessService::class);
});
