<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Italian\ItalianPayrollPreview;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\Swiss\SwissPayrollPreview;

function previewSame(string $actual, string $expected): void
{
    expect(Dec::cmp($actual, $expected))->toBe(0, "expected {$expected}, got {$actual}");
}

test('Swiss preview: statutory deductions at the maximum coordinated salary', function (): void {
    $employee = new EmployeeData(PayrollDates::date('2020-01-01'), baseAnnualSalary: '120000', dateOfBirth: PayrollDates::date('1980-05-01'));

    $result = SwissPayrollPreview::calculate($employee, PayrollDates::date('2026-01-01'));

    previewSame($result->grossMonthlySalary, '10000');
    previewSame($result->deductionBreakdown['AVS/AI/IPG'], '530.00');
    previewSame($result->deductionBreakdown['AD'], '110.00');
    previewSame($result->deductionBreakdown['LPP/BVG'], '401.63');
    previewSame($result->totalDeductions, '1041.63');
    previewSame($result->netMonthlySalary, '8958.37');
    expect(stripos($result->complianceNotes, 'Certified'))->toBeFalse();
});

test('Swiss preview: AD ceiling and withholding tax from the rate of the employee', function (): void {
    $employee = new EmployeeData(
        PayrollDates::date('2020-01-01'),
        baseAnnualSalary: '160000',
        dateOfBirth: PayrollDates::date('2000-02-01'),
        isWithholdingTaxSubject: true,
        withholdingTaxCode: 'A0N',
        withholdingTaxRatePercent: '12',
    );

    $result = SwissPayrollPreview::calculate($employee, PayrollDates::date('2026-01-01'));

    previewSame($result->deductionBreakdown['AVS/AI/IPG'], '706.67');
    previewSame($result->deductionBreakdown['AD'], '135.85');
    previewSame($result->deductionBreakdown['LPP/BVG'], '187.43');
    previewSame($result->deductionBreakdown['Imposta alla fonte'], '1600.00');
});

test('Swiss preview: AHV age rules', function (int $birthYear, string $expectedAhv): void {
    $employee = new EmployeeData(PayrollDates::date('2020-01-01'), baseAnnualSalary: '60000', dateOfBirth: PayrollDates::date("{$birthYear}-06-01"));

    $result = SwissPayrollPreview::calculate($employee, PayrollDates::date('2026-01-01'));

    previewSame($result->deductionBreakdown['AVS/AI/IPG'], $expectedAhv);
    previewSame($result->deductionBreakdown['AD'], '0');
})->with([
    [1958, '190.80'],
    [2010, '0.00'],
]);

test('Italian preview: 2026 brackets, art. 13 TUIR deduction and tax wedge', function (string $annual, int $installments, string $inps, string $irpef, string $net): void {
    $employee = new EmployeeData(PayrollDates::date('2020-01-01'), baseAnnualSalary: $annual, salaryInstallmentsPerYear: $installments);

    $result = ItalianPayrollPreview::calculate($employee, PayrollDates::date('2026-01-01'));

    previewSame($result->deductionBreakdown['Contributi INPS dipendente'], $inps);
    previewSame($result->deductionBreakdown['IRPEF netta'], $irpef);
    previewSame($result->netMonthlySalary, $net);
})->with([
    ['36000', 12, '275.70', '458.92', '2265.38'],
    ['18000', 12, '137.85', '65.23', '1362.30'],
]);

test('the 2026 IRPEF brackets are 23, 33 and 43 percent', function (): void {
    $parameters = new ItalianPayrollParameters;

    previewSame($parameters->grossIrpef('28000'), '6440');
    previewSame($parameters->grossIrpef('50000'), '13700');
    previewSame($parameters->grossIrpef('60000'), '18000');
});

test('annual parameters survive the round trip through the table', function (): void {
    $parameters = new ItalianPayrollParameters;

    expect(ItalianPayrollParameters::fromArray(2026, $parameters->toArray()))->toEqual($parameters)
        ->and($parameters->toArray())->toHaveKey('inps_first_bracket', '56224')
        ->and(ItalianPayrollParameters::fromArray(2027, ['inps_first_bracket' => '60000'])->inpsFirstBracket)->toBe('60000');
});
