<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollInputData;
use Modules\Payroll\Calculation\PayrollResult;
use Modules\Payroll\Calculation\Swiss\FamilyAllowanceRateData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollRules;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollYearToDate;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes as Codes;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;

/**
 * @param  array<string, mixed>  $overrides
 */
function chEmployee(string $annualSalary, int $installments, array $overrides = []): EmployeeData
{
    return new EmployeeData(...[
        'hireDate' => PayrollDates::date('2020-01-01'),
        'dateOfBirth' => PayrollDates::date('1986-05-10'),
        'gender' => 'M',
        'fullName' => 'Mario Rossi',
        'baseAnnualSalary' => $annualSalary,
        'salaryInstallmentsPerYear' => $installments,
        'workCanton' => 'TI',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function chContext(EmployeeData $employee, int $year, int $month, array $overrides = []): SwissPayrollContext
{
    return new SwissPayrollContext(...[
        'employee' => $employee,
        'year' => $year,
        'month' => $month,
        'wageTypes' => SwissWageTypeCatalog::byCode(),
        'settings' => new SwissPayrollSettingsData(
            nbuEmployeeRatePercent: '1.2',
            buEmployerRatePercent: '0.1',
            ktgEmployeeRatePercent: '0.5',
            ktgEmployerRatePercent: '0.5',
            fakEmployerRatePercent: '1.5',
        ),
        'familyAllowanceRates' => [new FamilyAllowanceRateData('TI', PayrollDates::date('2025-01-01'), '215', '268')],
        ...$overrides,
    ]);
}

function chSame(string $actual, string $expected): void
{
    expect(Dec::cmp($actual, $expected))->toBe(0, "expected {$expected}, got {$actual}");
}

function chAmount(PayrollResult $result, string $code, string $expected): void
{
    chSame($result->amount($code), $expected);
}

test('a full month of a monthly paid employee computes social contributions and BVG', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('84500', 13), 2026, 1));

    chSame($result->payslip->grossSalary, '6500');
    chAmount($result, Codes::Ahv, '-344.50');
    chAmount($result, Codes::Alv, '-71.50');
    chAmount($result, Codes::Nbu, '-78.00');
    chAmount($result, Codes::Ktg, '-32.50');
    chSame($result->payslip->bvgInsuredSalary, '58040');
    chAmount($result, Codes::Bvg, '-241.85');
    chAmount($result, Codes::BvgEmployer, '241.85');
    chSame($result->payslip->employeeDeductions, '768.35');
    chSame($result->payslip->netSalary, '5731.65');
    chSame($result->payslip->payoutAmount, '5731.65');
    expect($result->payslip->warnings)->toBeNull();
});

test('the 13th salary is paid in December pro rata of the paid days of the year', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('84500', 13), 2026, 12, [
        'yearToDate' => new SwissPayrollYearToDate('330', '71500', '71500'),
    ]));

    chAmount($result, Codes::ThirteenthSalary, '6500');
    chSame($result->payslip->grossSalary, '13000');
});

test('an employee hired on the 16th is paid 15 days with the 30-day convention', function (): void {
    $employee = chEmployee('78000', 12, ['hireDate' => PayrollDates::date('2026-03-16')]);

    $result = SwissPayrollEngine::calculate(chContext($employee, 2026, 3));

    chSame($result->payslip->paidDays, '15');
    chSame($result->payslip->grossSalary, '3250');
    expect(SwissPayrollEngine::calculate(chContext($employee, 2026, 2)))->toBeNull();
});

test('the ALV basis is capped at the cumulated annual ceiling', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('180000', 12), 2026, 1));

    chSame($result->payslip->ahvBasis, '15000');
    chSame($result->payslip->alvBasis, '12350');
    chSame($result->payslip->uvgBasis, '12350');
});

test('past the reference age the AHV allowance applies and no ALV is due', function (): void {
    $employee = chEmployee('60000', 12, ['gender' => 'F', 'dateOfBirth' => PayrollDates::date('1958-03-01')]);

    $result = SwissPayrollEngine::calculate(chContext($employee, 2026, 1));

    chSame($result->payslip->ahvBasis, '3600');
    chSame($result->payslip->alvBasis, '0');
    chAmount($result, Codes::Bvg, '0');
});

test('children receive the family allowances of the canton', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('78000', 12), 2026, 6, [
        'children' => [
            new ChildData(PayrollDates::date('2020-01-01')),
            new ChildData(PayrollDates::date('2008-01-01'), inEducation: true),
        ],
    ]));

    chAmount($result, Codes::ChildAllowance, '215');
    chAmount($result, Codes::EducationAllowance, '268');
    chSame($result->payslip->ahvBasis, '6500');
    chSame($result->payslip->grossSalary, '6983');
});

test('withholding tax uses the tariff row of the monthly income in a monthly-model canton', function (): void {
    $employee = chEmployee('78000', 12, [
        'isWithholdingTaxSubject' => true,
        'withholdingTaxCode' => 'A0N',
        'workCanton' => 'ZH',
        'residenceCanton' => 'ZH',
    ]);
    $validFrom = PayrollDates::date('2026-01-01');

    $result = SwissPayrollEngine::calculate(chContext($employee, 2026, 1, [
        'withholdingTaxTariffs' => [
            new WithholdingTaxTariffData('ZH', 'A0N', $validFrom, '0', '6000', '0', '5'),
            new WithholdingTaxTariffData('ZH', 'A0N', $validFrom, '6000', '1000', '0', '8'),
        ],
    ]));

    chSame($result->payslip->withholdingTaxRatePercent, '8');
    chAmount($result, Codes::WithholdingTax, '-520');
});

test('the AHV 21 reference age of women born in 1962 is 64 years and 6 months', function (): void {
    $birth = PayrollDates::date('1962-04-15');

    expect(SwissPayrollRules::referenceAgeDate($birth, 'F')->format('Y-m-d'))->toBe('2026-10-15')
        ->and(SwissPayrollRules::referenceAgeDate($birth, 'M')->format('Y-m-d'))->toBe('2027-04-15');

    chSame(SwissPayrollRules::holidayCompensationPercent(4), '8.33');
    chSame(SwissPayrollRules::holidayCompensationPercent(5), '10.64');
});

test('hourly wages use the rate of the employee and add the holiday compensation', function (): void {
    $employee = chEmployee('0', 12, ['payType' => 'hourly', 'hourlyWage' => '30', 'weeklyHours' => '20', 'holidayWeeks' => 4]);

    $result = SwissPayrollEngine::calculate(chContext($employee, 2026, 1, [
        'inputs' => [new PayrollInputData(Codes::HourlyWage, quantity: '100')],
    ]));

    chAmount($result, Codes::HourlyWage, '3000');
    chAmount($result, Codes::HolidayCompensation, '249.90');
    chSame($result->payslip->grossSalary, '3249.90');
});

test('a wage type that does not exist is skipped with a warning', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('60000', 12), 2026, 1, [
        'inputs' => [new PayrollInputData('9999', amount: '100')],
    ]));

    expect($result->payslip->warnings)->toBe('Genere salariale 9999 non definito: riga ignorata.')
        ->and($result->linesWithCode('9999'))->toBe([]);
});

test('a missing tariff falls back to the manual rate of the employee, or to a warning', function (): void {
    $manual = chEmployee('60000', 12, ['isWithholdingTaxSubject' => true, 'withholdingTaxCode' => 'A0N', 'withholdingTaxRatePercent' => '7.5']);
    $withoutRate = chEmployee('60000', 12, ['isWithholdingTaxSubject' => true, 'withholdingTaxCode' => 'A0N']);

    $result = SwissPayrollEngine::calculate(chContext($manual, 2026, 1));
    chAmount($result, Codes::WithholdingTax, '-375');
    expect($result->payslip->warnings)->toContain("non importata: usata l'aliquota manuale 7.5 %");

    $result = SwissPayrollEngine::calculate(chContext($withoutRate, 2026, 1));
    chAmount($result, Codes::WithholdingTax, '0');
    expect($result->payslip->warnings)->toContain('Imposta alla fonte non calcolata');
});

test('parameters of another year change the BVG limits, and warnings reach the payslip', function (): void {
    $result = SwissPayrollEngine::calculate(chContext(chEmployee('84500', 13), 2027, 1, [
        'parameters' => new SwissPayrollParameters(year: 2026, bvgCoordinationDeduction: '30000'),
        'parameterWarnings' => ['Parametri legali 2026 usati per il 2027: verificare e aggiornare i parametri annuali.'],
    ]));

    chSame($result->payslip->bvgInsuredSalary, '54500');
    expect($result->payslip->warnings)->toContain('Parametri legali 2026');
});

test('dates stop at the end of the month like the .NET DateTime', function (): void {
    expect(PayrollDates::addYears(PayrollDates::date('2024-02-29'), 1)->format('Y-m-d'))->toBe('2025-02-28')
        ->and(PayrollDates::addMonths(PayrollDates::date('2026-01-31'), 1)->format('Y-m-d'))->toBe('2026-02-28')
        ->and(PayrollDates::addMonths(PayrollDates::date('2026-03-15'), -6)->format('Y-m-d'))->toBe('2025-09-15')
        ->and(SwissPayrollRules::paidDays(2026, 2, PayrollDates::date('2020-01-01'), null))->toBe(30)
        ->and(SwissPayrollRules::paidDays(2026, 1, PayrollDates::date('2026-01-31'), null))->toBe(1);
});

test('contributions round to five centimes', function (string $amount, string $expected): void {
    chSame(SwissPayrollRules::roundTo5Centimes($amount), $expected);
})->with([
    ['10.02', '10.00'],
    ['10.03', '10.05'],
    ['10.07', '10.05'],
    ['10.08', '10.10'],
    ['100.00', '100.00'],
    ['-10.025', '-10.05'],
]);
