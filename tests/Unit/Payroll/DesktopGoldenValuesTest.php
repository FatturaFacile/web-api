<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\AbsenceData;
use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementLevelData;
use Modules\Payroll\Calculation\Italian\ItalianPayrollContext;
use Modules\Payroll\Calculation\Italian\ItalianPayrollEngine;
use Modules\Payroll\Calculation\Italian\ItalianPayrollPreview;
use Modules\Payroll\Calculation\Italian\ItalianPayrollSettingsData;
use Modules\Payroll\Calculation\Italian\ItalianPreviousPayslip;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollInputData;
use Modules\Payroll\Calculation\PayrollPreview;
use Modules\Payroll\Calculation\PayrollResult;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\Swiss\FamilyAllowanceRateData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollPreview;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollYearToDate;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;

/**
 * Random scenarios run through the C# engines of the desktop: the PHP engines must give the same payslip, line by line.
 *
 * @return array<string, mixed>
 */
function goldenValues(): array
{
    static $values = null;

    return $values ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/payroll/desktop_golden_values.json'), true, flags: JSON_THROW_ON_ERROR);
}

function goldenDate(?string $date): ?DateTimeImmutable
{
    return $date === null ? null : PayrollDates::date($date);
}

/**
 * @param  array<string, mixed>  $e
 */
function goldenEmployee(array $e): EmployeeData
{
    return new EmployeeData(
        hireDate: goldenDate($e['hire']),
        terminationDate: goldenDate($e['term']),
        dateOfBirth: goldenDate($e['dob']),
        gender: $e['gender'],
        baseAnnualSalary: $e['annual'],
        fteRatio: $e['fte'],
        salaryInstallmentsPerYear: $e['inst'],
        isFixedTermContract: $e['fixedTerm'],
        payType: $e['payType'],
        hourlyWage: $e['hourly'],
        weeklyHours: $e['weekly'],
        holidayWeeks: $e['holiday'],
        workCanton: $e['work'],
        residenceCountry: $e['resCountry'],
        residenceCanton: $e['resCanton'],
        isWithholdingTaxSubject: $e['qst'],
        withholdingTaxCode: $e['qstCode'],
        withholdingTaxRatePercent: $e['qstRate'],
        nonOccupationalAccidentRatePercent: $e['nbu'],
        occupationalAccidentRatePercent: $e['bu'],
        dailySicknessRatePercent: $e['ktg'],
        familyAllowanceFundRatePercent: $e['fak'],
        pensionFundEmployeeRatePercent: $e['pension'],
        regionalSurchargeRatePercent: $e['regional'],
        municipalSurchargeRatePercent: $e['municipal'],
    );
}

/**
 * @param  list<array<string, mixed>>  $inputs
 * @return list<PayrollInputData>
 */
function goldenInputs(array $inputs): array
{
    return array_map(static fn (array $i): PayrollInputData => new PayrollInputData($i['code'], $i['qty'], $i['rate'], $i['amount'], $i['note']), $inputs);
}

/**
 * @param  list<array<string, mixed>>  $absences
 * @return list<AbsenceData>
 */
function goldenAbsences(array $absences): array
{
    return array_map(static fn (array $a): AbsenceData => new AbsenceData($a['type'], goldenDate($a['start']), goldenDate($a['end']), $a['percent']), $absences);
}

/**
 * Swiss scenarios whose payslip no longer matches the desktop on purpose: the PHP engine applies rules the C# engine of
 * the desktop does not have yet (family allowances withheld below the LAFam minimum income, art. 13 para. 3 LAFam;
 * non-occupational accident insurance due by weekly hours whatever the pay type; withholding tax rate income extrapolated
 * to a full month and to the workload, Circular 45). They are skipped until the desktop engine and this fixture are
 * regenerated with the same rules; every other scenario is still compared line by line.
 *
 * @return list<int>
 */
function goldenDivergentSwissCases(): array
{
    return [3, 4, 7, 8, 14, 21, 29, 30, 43, 56, 61, 62, 63, 66];
}

function goldenSame(?string $actual, ?string $expected, string $label): void
{
    if ($actual === null || $expected === null) {
        expect($actual)->toBe($expected, $label);

        return;
    }

    expect(Dec::cmp($actual, $expected))->toBe(0, "{$label}: expected {$expected}, got {$actual}");
}

/**
 * @param  array<string, mixed>|null  $expected
 */
function goldenCheck(?array $expected, ?PayrollResult $actual): void
{
    if ($expected === null) {
        expect($actual)->toBeNull();

        return;
    }

    $slip = $expected['slip'];
    $payslip = $actual->payslip;

    foreach ([
        'paid' => $payslip->paidDays, 'gross' => $payslip->grossSalary, 'ahv' => $payslip->ahvBasis, 'alv' => $payslip->alvBasis,
        'uvg' => $payslip->uvgBasis, 'ktg' => $payslip->ktgBasis, 'bvg' => $payslip->bvgInsuredSalary, 'qstBasis' => $payslip->withholdingTaxBasis,
        'qstRate' => $payslip->withholdingTaxRatePercent, 'ded' => $payslip->employeeDeductions, 'net' => $payslip->netSalary,
        'payout' => $payslip->payoutAmount, 'employer' => $payslip->employerContributions,
    ] as $key => $value) {
        goldenSame($value, $slip[$key], $key);
    }

    expect($payslip->withholdingTaxCode)->toBe($slip['qstCode'])
        ->and($payslip->withholdingTaxCanton)->toBe($slip['qstCanton'])
        ->and($payslip->warnings)->toBe($slip['warnings'] === null ? null : str_replace("\r\n", "\n", $slip['warnings']))
        ->and(array_map(static fn (PayslipLineData $line): string => $line->wageTypeCode, $actual->lines))->toBe(array_column($expected['lines'], 'code'));

    foreach ($expected['lines'] as $index => $line) {
        $actualLine = $actual->lines[$index];
        expect($actualLine->description)->toBe($line['desc'])
            ->and($actualLine->kind)->toBe($line['kind'])
            ->and($actualLine->certificateBox)->toBe($line['box'])
            ->and($actualLine->sortOrder)->toBe($line['sort']);
        goldenSame($actualLine->quantity, $line['qty'], "{$line['code']} quantity");
        goldenSame($actualLine->rate, $line['rate'], "{$line['code']} rate");
        goldenSame($actualLine->amount, $line['amount'], "{$line['code']} amount");
    }
}

/**
 * @param  array<string, mixed>  $expected
 */
function goldenCheckPreview(array $expected, PayrollPreview $actual): void
{
    goldenSame($actual->grossMonthlySalary, $expected['gross'], 'gross');
    goldenSame($actual->totalDeductions, $expected['ded'], 'deductions');
    goldenSame($actual->netMonthlySalary, $expected['net'], 'net');
    goldenSame($actual->employerTotalCost, $expected['employer'], 'employer');
    expect($actual->complianceNotes)->toBe($expected['notes']);

    foreach ($expected['breakdown'] as $key => $value) {
        goldenSame($actual->deductionBreakdown[$key], $value, $key);
    }
}

test('Dec gives the same digits as System.Decimal', function (): void {
    foreach (goldenValues()['decimals'] as $value) {
        $quotient = Dec::div($value['a'], $value['c']);
        $product = Dec::mul($quotient, $value['b']);
        $second = Dec::div($product, $value['c']);

        goldenSame($quotient, $value['q'], 'quotient');
        goldenSame($product, $value['p'], 'product');
        goldenSame($second, $value['d'], 'second quotient');
        goldenSame(Dec::round($second), $value['r2'], 'rounded');
    }
});

test('the Swiss engine gives the payslips of the desktop', function (): void {
    $wageTypes = SwissWageTypeCatalog::byCode();
    $cases = goldenValues()['swiss'];

    expect(count($cases))->toBeGreaterThan(50);

    foreach ($cases as $index => $case) {
        if (in_array($index, goldenDivergentSwissCases(), true)) {
            continue;
        }

        $settings = $case['settings'];

        goldenCheck($case['result'], SwissPayrollEngine::calculate(new SwissPayrollContext(
            employee: goldenEmployee($case['employee']),
            year: 2026,
            month: $case['month'],
            wageTypes: $wageTypes,
            settings: new SwissPayrollSettingsData(
                ahvAdministrationCostPercent: $settings['admin'],
                nbuEmployeeRatePercent: $settings['nbu'],
                buEmployerRatePercent: $settings['bu'],
                ktgEmployeeRatePercent: $settings['ktg'],
                ktgEmployerRatePercent: $settings['ktgE'],
                fakEmployerRatePercent: $settings['fak'],
                bvgEmployerSharePercent: $settings['share'],
                bvgRiskRatePercent: $settings['risk'],
                defaultWorkCanton: $settings['canton'],
            ),
            inputs: goldenInputs($case['inputs']),
            absences: goldenAbsences($case['absences']),
            children: array_map(static fn (array $c): ChildData => new ChildData(goldenDate($c['dob']), $c['edu'], goldenDate($c['eduEnd']), $c['eligible']), $case['children']),
            familyAllowanceRates: array_map(static fn (array $r): FamilyAllowanceRateData => new FamilyAllowanceRateData($r['canton'], goldenDate($r['from']), $r['child'], $r['edu']), $case['rates']),
            withholdingTaxTariffs: array_map(static fn (array $t): WithholdingTaxTariffData => new WithholdingTaxTariffData($t['canton'], $t['code'], goldenDate($t['from']), $t['income'], $t['step'], $t['min'], $t['rate']), $case['tariffs']),
            yearToDate: new SwissPayrollYearToDate($case['ytd']['days'], $case['ytd']['alv'], $case['ytd']['uvg']),
        )));
    }
});

test('the Italian engine gives the payslips of the desktop', function (): void {
    $cases = goldenValues()['italian'];

    expect(count($cases))->toBeGreaterThan(50);

    foreach ($cases as $case) {
        goldenCheck($case['result'], ItalianPayrollEngine::calculate(new ItalianPayrollContext(
            employee: goldenEmployee($case['employee']),
            year: 2026,
            month: $case['month'],
            settings: $case['settings'] === null ? null : new ItalianPayrollSettingsData($case['settings']['employee'], $case['settings']['employer']),
            agreement: $case['agreement'] === null ? null : new CollectiveAgreementData($case['agreement']['inst'], $case['agreement']['fourteenth'], $case['agreement']['rate']),
            level: $case['level'] === null ? null : new CollectiveAgreementLevelData($case['level']['level'], $case['level']['min']),
            inputs: goldenInputs($case['inputs']),
            absences: goldenAbsences($case['absences']),
            yearToDate: array_map(static fn (array $y): ItalianPreviousPayslip => new ItalianPreviousPayslip($y['inps'], $y['taxable'], $y['lines']), $case['ytd']),
            previousYearTaxable: $case['previous'],
        )));
    }
});

test('the previews give the figures of the country strategies of the desktop', function (): void {
    foreach (goldenValues()['previews'] as $case) {
        $employee = goldenEmployee($case['employee']);
        $target = goldenDate($case['target']);

        goldenCheckPreview($case['ch'], SwissPayrollPreview::calculate($employee, $target));
        goldenCheckPreview($case['it'], ItalianPayrollPreview::calculate($employee, $target));
    }
});
