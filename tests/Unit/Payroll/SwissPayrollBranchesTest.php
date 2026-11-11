<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\Swiss\FamilyAllowanceRateData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes as Codes;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;

/**
 * Branches of the Swiss payroll that no other test reached (measured with pcov): family allowances below the LAFam
 * minimum income or without cantonal amounts, and the canton of the withholding tax of a person resident abroad.
 *
 * The LAFam minimum of CHF 630 a month (art. 13 al. 3 LAFam) is the repository default and was NOT re-read on a primary
 * source for 2026: it is used as the engine's own parameter, the test checks only that the engine applies it. Allowance
 * amounts below are test inputs, not cantonal figures.
 *
 * @param  array<string, mixed>  $overrides
 */
function branchContext(EmployeeData $employee, array $overrides = []): SwissPayrollContext
{
    return new SwissPayrollContext(...[
        'employee' => $employee,
        'year' => 2026,
        'month' => 3,
        'wageTypes' => SwissWageTypeCatalog::byCode(),
        'settings' => new SwissPayrollSettingsData,
        'children' => [new ChildData(PayrollDates::date('2020-01-01'))],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function branchEmployee(string $annualSalary, array $overrides = []): EmployeeData
{
    return new EmployeeData(...[
        'hireDate' => PayrollDates::date('2015-01-01'),
        'dateOfBirth' => PayrollDates::date('1986-05-10'),
        'gender' => 'M',
        'fullName' => 'Mario Rossi',
        'baseAnnualSalary' => $annualSalary,
        'salaryInstallmentsPerYear' => 12,
        'workCanton' => 'TI',
        ...$overrides,
    ]);
}

test('no family allowance is paid below the minimum monthly income, and the payslip says so', function (): void {
    // 6,000 a year = 500 a month, below 630.
    $result = SwissPayrollEngine::calculate(branchContext(branchEmployee('6000'), [
        'familyAllowanceRates' => [new FamilyAllowanceRateData('TI', PayrollDates::date('2025-01-01'), '215', '268')],
    ]));

    expect(Dec::cmp($result->amount(Codes::ChildAllowance), '0'))->toBe(0)
        ->and($result->payslip->warnings)->toContain('minimo LAFam di CHF 630/mese');
});

test('a missing cantonal allowance table is reported and nothing is paid', function (): void {
    $result = SwissPayrollEngine::calculate(branchContext(branchEmployee('78000'), ['familyAllowanceRates' => []]));

    expect(Dec::cmp($result->amount(Codes::ChildAllowance), '0'))->toBe(0)
        ->and($result->payslip->warnings)->toContain('Importi degli assegni familiari mancanti per il cantone TI');
});

test('the withholding tax of a person resident abroad is due to the canton of work, of a resident to the canton of residence', function (): void {
    $tariffs = [
        new WithholdingTaxTariffData('TI', 'A0N', PayrollDates::date('2026-01-01'), '0', '100000', '0', '10'),
        new WithholdingTaxTariffData('ZH', 'A0N', PayrollDates::date('2026-01-01'), '0', '100000', '0', '5'),
    ];

    $frontier = SwissPayrollEngine::calculate(branchContext(
        branchEmployee('78000', ['isWithholdingTaxSubject' => true, 'withholdingTaxCode' => 'A0N', 'residenceCountry' => 'IT', 'residenceCanton' => 'ZH', 'workCanton' => 'TI']),
        ['children' => [], 'withholdingTaxTariffs' => $tariffs],
    ));
    $resident = SwissPayrollEngine::calculate(branchContext(
        branchEmployee('78000', ['isWithholdingTaxSubject' => true, 'withholdingTaxCode' => 'A0N', 'residenceCountry' => 'CH', 'residenceCanton' => 'ZH', 'workCanton' => 'TI']),
        ['children' => [], 'withholdingTaxTariffs' => $tariffs],
    ));

    // 6,500 x 10% = 650.00 for the canton of work; 6,500 x 5% = 325.00 for the canton of residence.
    expect($frontier->payslip->withholdingTaxCanton)->toBe('TI')
        ->and(Dec::cmp($frontier->amount(Codes::WithholdingTax), '-650'))->toBe(0)
        ->and($resident->payslip->withholdingTaxCanton)->toBe('ZH')
        ->and(Dec::cmp($resident->amount(Codes::WithholdingTax), '-325'))->toBe(0);
});
