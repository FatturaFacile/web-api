<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Contracts\Payroll\PayrollRunServiceInterface;
use Modules\HumanResources\Contracts\Payroll\RateSetResolverInterface;
use Modules\HumanResources\Contracts\Payroll\SalaryDeclarationExporterInterface;
use Modules\HumanResources\Contracts\Payroll\SwissPayrollCalculatorInterface;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Contracts\Payroll\WithholdingTaxServiceInterface;
use Modules\HumanResources\Events\PayrollRunApprovedEvent;
use Modules\HumanResources\Events\PayrollRunClosedEvent;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\PayslipLine;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;
use Modules\HumanResources\Models\Payroll\WageType;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;

test('all Swiss payroll models extend BaseEnterpriseModel', function (string $modelClass): void {
    expect(is_subclass_of($modelClass, BaseEnterpriseModel::class))->toBeTrue();
})->with([
    PayrollRateSet::class,
    WageType::class,
    WithholdingTariff::class,
    WithholdingTariffBracket::class,
    PayrollProfile::class,
    PayrollRun::class,
    Payslip::class,
    PayslipLine::class,
    SalaryDeclaration::class,
]);

test('Swiss payroll domain events implement DomainEventInterface and carry the run totals', function (): void {
    foreach ([PayrollRunApprovedEvent::class, PayrollRunClosedEvent::class] as $eventClass) {
        expect(is_subclass_of($eventClass, DomainEventInterface::class))->toBeTrue();
    }

    $event = new PayrollRunClosedEvent('run-1', 'PR-202601-001', '2026-01', '2026-01-25', 'CHF', 1, 800_000, 91_575, 92_175, 0, 708_425, 'tenant-1', 'tenant-1');

    expect($event->getEventName())->toBe('hr.payroll.run.closed')
        ->and($event->getAggregateType())->toBe('hr_payroll_run')
        ->and($event->getAggregateId())->toBe('run-1')
        ->and($event->toPayload())->toHaveKeys(['grossRappen', 'employeeDeductionsRappen', 'employerContributionsRappen', 'withholdingTaxRappen', 'netRappen']);
});

test('the Swiss payroll contracts are bound in the container', function (): void {
    foreach ([
        SwissPayrollCalculatorInterface::class,
        RateSetResolverInterface::class,
        WithholdingTaxServiceInterface::class,
        PayrollRunServiceInterface::class,
        SalaryDeclarationExporterInterface::class,
        WithholdingTariffImporterInterface::class,
    ] as $contract) {
        expect(app($contract))->toBeInstanceOf($contract);
    }
});

test('payroll code never hard-codes social insurance rates', function (): void {
    $calculator = (string) file_get_contents(base_path('modules/18_HumanResources/src/Services/Payroll/SwissPayrollCalculator.php'));

    // Typical rate literals (5.3%, 1.1%, 148'200 CHF ALV ceiling, 26'460 CHF coordination deduction) must come from data.
    foreach (['53000', '5.3', '1.1', '148200', '26460', '22680'] as $literal) {
        expect($calculator)->not->toContain($literal);
    }
});
