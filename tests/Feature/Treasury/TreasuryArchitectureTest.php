<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
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
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountBalance;
use Modules\Treasury\Models\BankAccountSignatory;
use Modules\Treasury\Models\BankBranch;
use Modules\Treasury\Models\BankConnection;
use Modules\Treasury\Models\BankGuarantee;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\BankTransaction;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\CashForecastLine;
use Modules\Treasury\Models\CashPool;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\FxPosition;
use Modules\Treasury\Models\FxRevaluation;
use Modules\Treasury\Models\IntercompanyFunding;
use Modules\Treasury\Models\InterestSchedule;
use Modules\Treasury\Models\LetterOfCredit;
use Modules\Treasury\Models\LoanAgreement;
use Modules\Treasury\Models\LoanDrawdown;
use Modules\Treasury\Models\PaymentApproval;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentExecution;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Treasury\Models\ReconciliationMatch;
use Modules\Treasury\Models\TreasuryAlert;
use Modules\Treasury\Models\WorkingCapitalMetric;
use Modules\Treasury\Services\BankReconciliationService;
use Modules\Treasury\Services\CashForecastService;
use Modules\Treasury\Services\ForeignExchangeRiskService;
use Modules\Treasury\Services\LiquidityManagementService;
use Modules\Treasury\Services\PaymentFactoryService;
use Modules\Treasury\Services\ReceivableCollectionService;
use Modules\Treasury\Services\TreasuryPositionService;

test('all treasury domain models inherit from BaseEnterpriseModel', function () {
    $models = [
        BankAccount::class,
        BankBranch::class,
        BankAccountSignatory::class,
        BankAccountBalance::class,
        BankConnection::class,
        BankStatement::class,
        BankStatementLine::class,
        BankTransaction::class,
        BankReconciliation::class,
        ReconciliationMatch::class,
        PaymentBatch::class,
        PaymentLine::class,
        PaymentApproval::class,
        PaymentExecution::class,
        ForecastScenario::class,
        CashForecast::class,
        CashForecastLine::class,
        CashPool::class,
        IntercompanyFunding::class,
        WorkingCapitalMetric::class,
        FxPosition::class,
        FxRevaluation::class,
        LoanAgreement::class,
        LoanDrawdown::class,
        InterestSchedule::class,
        BankGuarantee::class,
        LetterOfCredit::class,
        TreasuryAlert::class,
        ReceivableCollection::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('all treasury domain events implement DomainEventInterface and provide getCompanyId', function () {
    $events = [
        PaymentScheduledEvent::class,
        PaymentExecutedEvent::class,
        CollectionReceivedEvent::class,
        BankStatementImportedEvent::class,
        BankReconciliationCompletedEvent::class,
        CashForecastGeneratedEvent::class,
        LiquidityAlertEvent::class,
        TreasuryAllocationExecutedEvent::class,
        CashPositionUpdatedEvent::class,
        FXAdjustmentCalculatedEvent::class,
    ];

    foreach ($events as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue();
        expect($reflection->hasMethod('getCompanyId'))->toBeTrue();
        expect($reflection->hasMethod('toPayload'))->toBeTrue();
    }
});

test('all treasury domain services are registered in the service container', function () {
    expect(app(TreasuryPositionServiceInterface::class))->toBeInstanceOf(TreasuryPositionService::class)
        ->and(app(CashForecastServiceInterface::class))->toBeInstanceOf(CashForecastService::class)
        ->and(app(PaymentFactoryServiceInterface::class))->toBeInstanceOf(PaymentFactoryService::class)
        ->and(app(ReceivableCollectionServiceInterface::class))->toBeInstanceOf(ReceivableCollectionService::class)
        ->and(app(BankReconciliationServiceInterface::class))->toBeInstanceOf(BankReconciliationService::class)
        ->and(app(LiquidityManagementServiceInterface::class))->toBeInstanceOf(LiquidityManagementService::class)
        ->and(app(ForeignExchangeRiskServiceInterface::class))->toBeInstanceOf(ForeignExchangeRiskService::class);
});
