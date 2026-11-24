<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\AbsenceData;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementLevelData;
use Modules\Payroll\Calculation\Italian\ItalianPayrollContext;
use Modules\Payroll\Calculation\Italian\ItalianPayrollEngine;
use Modules\Payroll\Calculation\Italian\ItalianPayrollSettingsData;
use Modules\Payroll\Calculation\Italian\ItalianPreviousPayslip;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes as Codes;
use Modules\Payroll\Calculation\PayrollDates;

/**
 * @param  array<string, mixed>  $employee
 * @param  array<string, mixed>  $overrides
 */
function itContext(int $month, string $minimum = '1800', array $employee = [], array $overrides = []): ItalianPayrollContext
{
    return new ItalianPayrollContext(...[
        'employee' => new EmployeeData(...[
            'hireDate' => PayrollDates::date('2020-01-01'),
            'fullName' => 'Mario Rossi',
            'baseAnnualSalary' => '30000',
            'salaryInstallmentsPerYear' => 13,
            ...$employee,
        ]),
        'year' => 2026,
        'month' => $month,
        'agreement' => new CollectiveAgreementData(installments: 13),
        'level' => new CollectiveAgreementLevelData('4', $minimum),
        'settings' => new ItalianPayrollSettingsData,
        ...$overrides,
    ]);
}

function itSame(string $actual, string $expected): void
{
    expect(Dec::cmp($actual, $expected))->toBe(0, "expected {$expected}, got {$actual}");
}

test('a normal month withholds INPS 9.19% on the rounded basis and keeps the net consistent', function (): void {
    $result = ItalianPayrollEngine::calculate(itContext(3));
    $payslip = $result->payslip;

    itSame($payslip->grossSalary, '2307.69');
    itSame($payslip->ahvBasis, '2308');
    itSame($result->amount(Codes::InpsEmployee), '-212.11');
    expect(Dec::isNegative($result->amount(Codes::Irpef)))->toBeTrue();
    itSame($payslip->netSalary, Dec::sub($payslip->grossSalary, $payslip->employeeDeductions));
    expect($result->linesWithCode(Codes::Thirteenth))->toBe([])
        ->and($result->linesWithCode(Codes::Tfr))->not->toBe([]);
});

test('December pays the thirteenth installment and applies the year-end adjustment', function (): void {
    $result = ItalianPayrollEngine::calculate(itContext(12));

    itSame($result->amount(Codes::Thirteenth), '2307.69');
    expect($result->payslip->warnings)->toContain('Conguaglio');
});

test('pay below the minimum of the agreement level is flagged', function (): void {
    $result = ItalianPayrollEngine::calculate(itContext(3, '2500'));

    expect($result->payslip->warnings)->toContain('minimo')
        ->and($result->payslip->warnings)->toContain('Retribuzione mensile 2307.69 inferiore al minimo del livello 4 (2500.00).');
});

test('employees hired after the month get no payslip', function (): void {
    expect(ItalianPayrollEngine::calculate(itContext(3, employee: ['hireDate' => PayrollDates::date('2026-05-01')])))->toBeNull();
});

test('the fourteenth installment is paid in the month of the agreement', function (): void {
    $agreement = new CollectiveAgreementData(installments: 14, fourteenthMonth: 6);

    $result = ItalianPayrollEngine::calculate(itContext(6, employee: ['baseAnnualSalary' => '28000'], overrides: ['agreement' => $agreement]));

    itSame($result->amount(Codes::Fourteenth), '2000');
    expect(ItalianPayrollEngine::calculate(itContext(7, overrides: ['agreement' => $agreement]))->linesWithCode(Codes::Fourteenth))->toBe([]);
});

test('previous year surcharges are withheld in 11 installments until November', function (): void {
    $employee = ['regionalSurchargeRatePercent' => '1.73', 'municipalSurchargeRatePercent' => '0.8'];

    $march = ItalianPayrollEngine::calculate(itContext(3, employee: $employee, overrides: ['previousYearTaxable' => '27500']));
    $december = ItalianPayrollEngine::calculate(itContext(12, employee: $employee, overrides: ['previousYearTaxable' => '27500']));

    itSame($march->amount(Codes::RegionalSurcharge), '-43.25');
    itSame($march->amount(Codes::MunicipalSurcharge), '-20.00');
    expect($december->linesWithCode(Codes::RegionalSurcharge))->toBe([]);
});

test('the 1% INPS applies only to the part above the first pensionable bracket', function (): void {
    $previous = new ItalianPreviousPayslip('55000', '50000');

    $result = ItalianPayrollEngine::calculate(itContext(3, employee: ['baseAnnualSalary' => '39000'], overrides: ['yearToDate' => [$previous]]));

    itSame($result->payslip->ahvBasis, '3000');
    itSame($result->amount(Codes::InpsEmployee), '-293.46');
});

test('the conguaglio refunds IRPEF withheld in excess and reports it as a refund', function (): void {
    $previous = new ItalianPreviousPayslip('20000', '20000', [Codes::Irpef => '-9000']);

    $result = ItalianPayrollEngine::calculate(itContext(12, overrides: ['yearToDate' => [$previous]]));

    expect(Dec::isPositive($result->amount(Codes::Irpef)))->toBeTrue()
        ->and($result->linesWithCode(Codes::Irpef)[0]->description)->toBe('Rimborso IRPEF da conguaglio');
});

test('sickness in the month raises the warning about the INPS indemnities', function (): void {
    $absence = new AbsenceData('Sickness', PayrollDates::date('2026-03-02'), PayrollDates::date('2026-03-03'));

    expect(ItalianPayrollEngine::calculate(itContext(3, overrides: ['absences' => [$absence]]))->payslip->warnings)
        ->toContain('Malattia, infortunio o maternità');
});

test('unpaid absences reduce the pay on 26 conventional days', function (): void {
    $absence = new AbsenceData('Unpaid', PayrollDates::date('2026-03-02'), PayrollDates::date('2026-03-03'));

    $result = ItalianPayrollEngine::calculate(itContext(3, overrides: ['absences' => [$absence]]));

    itSame($result->payslip->paidDays, '24');
    itSame($result->amount(Codes::UnpaidAbsence), '-177.51');
});
