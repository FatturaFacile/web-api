<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementData;
use Modules\Payroll\Calculation\Italian\CollectiveAgreementLevelData;
use Modules\Payroll\Calculation\Italian\ItalianPayrollContext;
use Modules\Payroll\Calculation\Italian\ItalianPayrollEngine;
use Modules\Payroll\Calculation\Italian\ItalianPayrollSettingsData;
use Modules\Payroll\Calculation\Italian\ItalianPreviousPayslip;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes as ItCodes;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollYearToDate;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes as ChCodes;

/**
 * Independent reference check of the Italian and Swiss payroll engines (modules/18_Payroll). The expected values are
 * computed here with plain bcmath from the inputs of each scenario, rounding half away from zero, NOT with the engine
 * helpers. This file does not touch DesktopGoldenValuesTest or the golden fixture: the Swiss cases that diverge from the
 * desktop engine on purpose are documented there.
 *
 * ITALY. Figures and sources (all read on 2026-11-11):
 * - IRPEF 2026: 23% to 28,000; 33% from 28,001 to 50,000 (reduced from 35% by the 2026 budget law); 43% above. MEF, "Principali
 *   misure della legge di bilancio 2026"; checked by the MEF figure of EUR 13,700 of tax at 50,000 (6,440 + 7,260).
 * - Employee work income deduction, art. 13 TUIR: 1,955 up to 15,000; 1,910 + 1,190 x (28,000 - R) / 13,000 up to 28,000;
 *   1,910 x (50,000 - R) / 22,000 up to 50,000; none above (Agenzia delle Entrate, circolare 4/E 2022, and the instructions of
 *   Modello Redditi/730 2026 for the 1,955 maximum made structural by D.Lgs. 192/2024). The increase of EUR 65 between 25,000 and
 *   35,000 is in circolare 4/E 2022 but was NOT found again in the 2025-2026 sources: UNVERIFIED for 2026.
 * - Employee INPS 9.19% and the additional 1% on the pay above the first pensionable bracket of EUR 56,224 for 2026: INPS
 *   circolare 30 January 2026 n. 6 (the 9.19% itself depends on the size of the employer: taken from the repository setting).
 * - TFR: yearly pay / 13.5 less 0.50% of the pensionable base (art. 2120 c.c.; L. 297/1982): from the repository, not re-read.
 * - UNVERIFIED and therefore only mirrored as stated by the engine's own parameter docblock (Legge 207/2024): the additional
 *   deduction of 1,000 between 20,000 and 32,000 decreasing to 40,000 and the non-taxable "bonus" of 7.1% / 5.3% / 4.8%.
 *
 * SWITZERLAND. AHV/IV/EO 10.6% in total, 5.3% each side, no ceiling; ALV 2.2% in total, 1.1% each side up to the insured
 * earnings of CHF 148,200 a year (2026): ahv-iv.ch and SECO fact sheet, read 2026-11-11. AHV allowance for people past the
 * reference age: CHF 1,400 a month (art. 4 AHVG, repository value, not re-read). BVG age credits 7/10/15/18% by age bracket
 * (art. 16 BVG, from memory of the statute: UNVERIFIED here) and limits entry 22,680, coordination deduction 26,460, maximum 90,720,
 * minimum coordinated 3,780 (BVG 2025; they follow from the maximum AHV pension of 30,240 by 3/4, 7/8, 3x and 1/8 but the
 * 30,240 itself was NOT re-read: UNVERIFIED). Rounding of employee deductions to 5 centimes is the desktop convention.
 */
function refRound(string $value, int $places = 2): string
{
    return bcround($value, $places, RoundingMode::HalfAwayFromZero);
}

/**
 * Rounds to 5 centimes, half away from zero (the desktop convention of the Swiss payroll).
 */
function refFiveCentimes(string $value): string
{
    return bcdiv(refRound(bcmul($value, '20', 6), 0), '20', 2);
}

function refItalianGrossIrpef(string $income): string
{
    $tax = bcmul(bccomp($income, '28000', 6) > 0 ? '28000' : $income, '0.23', 6);

    if (bccomp($income, '28000', 6) > 0) {
        $tax = bcadd($tax, bcmul(bcsub(bccomp($income, '50000', 6) > 0 ? '50000' : $income, '28000', 6), '0.33', 6), 6);
    }

    if (bccomp($income, '50000', 6) > 0) {
        $tax = bcadd($tax, bcmul(bcsub($income, '50000', 6), '0.43', 6), 6);
    }

    return $tax;
}

function refItalianWorkDeduction(string $income): string
{
    if (bccomp($income, '15000', 6) <= 0) {
        $deduction = '1955';
    } elseif (bccomp($income, '28000', 6) <= 0) {
        $deduction = bcadd('1910', bcdiv(bcmul('1190', bcsub('28000', $income, 6), 6), '13000', 6), 6);
    } elseif (bccomp($income, '50000', 6) <= 0) {
        $deduction = bcdiv(bcmul('1910', bcsub('50000', $income, 6), 6), '22000', 6);
    } else {
        $deduction = '0';
    }

    return bccomp($income, '25000', 6) > 0 && bccomp($income, '35000', 6) <= 0 ? bcadd($deduction, '65', 6) : $deduction;
}

function refItalianWedgeDeduction(string $income): string
{
    return match (true) {
        bccomp($income, '20000', 6) <= 0 => '0',
        bccomp($income, '32000', 6) <= 0 => '1000',
        bccomp($income, '40000', 6) <= 0 => bcdiv(bcmul('1000', bcsub('40000', $income, 6), 6), '8000', 6),
        default => '0',
    };
}

function refItalianWedgeBonus(string $income): string
{
    return match (true) {
        bccomp($income, '0', 6) <= 0 => '0',
        bccomp($income, '8500', 6) <= 0 => bcmul($income, '0.071', 6),
        bccomp($income, '15000', 6) <= 0 => bcmul($income, '0.053', 6),
        bccomp($income, '20000', 6) <= 0 => bcmul($income, '0.048', 6),
        default => '0',
    };
}

/**
 * A regular (not December) month of an employee paid in 13 installments, with no payslip before.
 *
 * @return array{gross: string, inps: string, irpef: string, bonus: string, tfr: string, employerInps: string, net: string}
 */
function refItalianMonth(string $annualSalary, string $previousInpsBasis = '0'): array
{
    $gross = refRound(bcdiv($annualSalary, '13', 6));
    $basis = refRound($gross, 0);
    $above = bcsub(
        bccomp(bcsub(bcadd($previousInpsBasis, $basis, 2), '56224', 2), '0', 2) > 0 ? bcsub(bcadd($previousInpsBasis, $basis, 2), '56224', 2) : '0',
        bccomp(bcsub($previousInpsBasis, '56224', 2), '0', 2) > 0 ? bcsub($previousInpsBasis, '56224', 2) : '0',
        2,
    );
    $inps = refRound(bcadd(bcdiv(bcmul($basis, '9.19', 6), '100', 6), bcmul($above, '0.01', 6), 6));
    $taxable = bcsub($gross, $inps, 2);
    $projected = bcmul($taxable, '12', 2);
    $annualIrpef = bcsub(bcsub(refItalianGrossIrpef($projected), refItalianWorkDeduction($projected), 6), refItalianWedgeDeduction($projected), 6);
    $annualIrpef = bccomp($annualIrpef, '0', 6) > 0 ? refRound($annualIrpef) : '0.00';
    $irpef = refRound(bcdiv($annualIrpef, '12', 6));
    $bonus = refRound(bcdiv(refRound(refItalianWedgeBonus($projected)), '12', 6));

    return [
        'gross' => $gross,
        'inps' => $inps,
        'irpef' => $irpef,
        'bonus' => $bonus,
        'tfr' => refRound(bcsub(bcdiv($gross, '13.5', 6), bcmul($basis, '0.005', 6), 6)),
        'employerInps' => refRound(bcdiv(bcmul($basis, '29', 6), '100', 6)),
        'net' => bcadd(bcsub(bcsub($gross, $inps, 2), $irpef, 2), $bonus, 2),
    ];
}

function refItalianContext(string $annualSalary, int $month, array $yearToDate = []): ItalianPayrollContext
{
    return new ItalianPayrollContext(
        employee: new EmployeeData(hireDate: PayrollDates::date('2020-01-01'), fullName: 'Mario Rossi', baseAnnualSalary: $annualSalary, salaryInstallmentsPerYear: 13),
        year: 2026,
        month: $month,
        agreement: new CollectiveAgreementData(installments: 13),
        level: new CollectiveAgreementLevelData('4', '1000'),
        settings: new ItalianPayrollSettingsData,
        yearToDate: $yearToDate,
    );
}

dataset('italian monthly scenarios', [
    'IT1 EUR 18,000 a year' => ['18000'],
    'IT2 EUR 24,000 a year' => ['24000'],
    'IT3 EUR 30,000 a year' => ['30000'],
    'IT4 EUR 36,400 a year' => ['36400'],
    'IT5 EUR 45,500 a year' => ['45500'],
    'IT6 EUR 65,000 a year' => ['65000'],
    'IT7 EUR 120,000 a year' => ['120000'],
    'IT8 EUR 9,100 a year' => ['9100'],
]);

it('computes the Italian regular month like the hand reference', function (string $annualSalary): void {
    $reference = refItalianMonth($annualSalary);
    $result = ItalianPayrollEngine::calculate(refItalianContext($annualSalary, 3));

    expect(Dec::cmp($result->payslip->grossSalary, $reference['gross']))->toBe(0)
        ->and(Dec::cmp($result->amount(ItCodes::InpsEmployee), '-'.$reference['inps']))->toBe(0, "INPS expected -{$reference['inps']}, got {$result->amount(ItCodes::InpsEmployee)}")
        ->and(Dec::cmp($result->amount(ItCodes::Irpef), '-'.$reference['irpef']))->toBe(0, "IRPEF expected -{$reference['irpef']}, got {$result->amount(ItCodes::Irpef)}")
        ->and(Dec::cmp($result->amount(ItCodes::TaxWedge), $reference['bonus']))->toBe(0, "bonus expected {$reference['bonus']}, got {$result->amount(ItCodes::TaxWedge)}")
        ->and(Dec::cmp($result->amount(ItCodes::Tfr), $reference['tfr']))->toBe(0, "TFR expected {$reference['tfr']}, got {$result->amount(ItCodes::Tfr)}")
        ->and(Dec::cmp($result->amount(ItCodes::InpsEmployer), $reference['employerInps']))->toBe(0)
        ->and(Dec::cmp($result->payslip->payoutAmount, $reference['net']))->toBe(0, "payout expected {$reference['net']}, got {$result->payslip->payoutAmount}");
})->with('italian monthly scenarios');

it('adds the 1% INPS only above the first pensionable bracket, like the hand reference', function (): void {
    // Previous pay basis 56,000; this month 4,615 (60,000 / 13): the part above 56,224 is 56,000 + 4,615 - 56,224 = 4,391.
    $reference = refItalianMonth('60000', '56000');
    $result = ItalianPayrollEngine::calculate(refItalianContext('60000', 3, [new ItalianPreviousPayslip('56000', '50000')]));

    expect($reference['inps'])->toBe('468.03')
        ->and(Dec::cmp($result->amount(ItCodes::InpsEmployee), '-468.03'))->toBe(0);
});

it('pays the thirteenth in December as the monthly pay for the months employed and conguaglia IRPEF on the year', function (): void {
    // 30,000 / 13 = 2,307.69; employed all 12 months: 13th = 2,307.69 x 12 / 12.
    $previous = [];

    for ($month = 1; $month <= 11; $month++) {
        $payslip = ItalianPayrollEngine::calculate(refItalianContext('30000', $month, $previous));
        $previous[] = new ItalianPreviousPayslip(
            $payslip->payslip->ahvBasis,
            $payslip->payslip->withholdingTaxBasis,
            [ItCodes::Irpef => $payslip->amount(ItCodes::Irpef), ItCodes::TaxWedge => $payslip->amount(ItCodes::TaxWedge)],
        );
    }

    $december = ItalianPayrollEngine::calculate(refItalianContext('30000', 12, $previous));

    // Annual reference: 12 monthly pays + 13th = 13 x 2,307.69 = 29,999.97 gross; INPS per payslip on rounded bases.
    $monthlyGross = '2307.69';
    $monthlyBasis = '2308';
    $monthlyInps = refRound(bcdiv(bcmul($monthlyBasis, '9.19', 6), '100', 6));
    $decemberGross = bcmul($monthlyGross, '2', 2);
    $decemberBasis = refRound($decemberGross, 0);
    $decemberInps = refRound(bcdiv(bcmul($decemberBasis, '9.19', 6), '100', 6));
    $annualTaxable = bcadd(bcmul(bcsub($monthlyGross, $monthlyInps, 2), '11', 2), bcsub($decemberGross, $decemberInps, 2), 2);
    $annualIrpef = refRound(bcsub(bcsub(refItalianGrossIrpef($annualTaxable), refItalianWorkDeduction($annualTaxable), 6), refItalianWedgeDeduction($annualTaxable), 6));

    $withheld = '0';

    foreach ($previous as $payslip) {
        $withheld = bcadd($withheld, bcmul($payslip->lineAmounts[ItCodes::Irpef], '-1', 2), 2);
    }

    expect(Dec::cmp($december->amount(ItCodes::Thirteenth), $monthlyGross))->toBe(0)
        ->and(Dec::cmp($december->amount(ItCodes::Irpef), bcmul(bcsub($annualIrpef, $withheld, 2), '-1', 2)))->toBe(0, 'December IRPEF expected '.bcsub($annualIrpef, $withheld, 2).", got {$december->amount(ItCodes::Irpef)}");
});

/**
 * The Swiss contributions of a regular month, from the statute figures above (employee side).
 */
function refSwissMonth(string $annualSalary, int $birthYear, int $year = 2026): array
{
    $monthly = bcdiv($annualSalary, '12', 6);
    $ceilingMonthly = bcdiv('148200', '12', 6);
    $alvBasis = bccomp($monthly, $ceilingMonthly, 6) > 0 ? $ceilingMonthly : $monthly;
    $age = $year - $birthYear;
    $credit = match (true) {
        $age < 25 => '0',
        $age < 35 => '7',
        $age < 45 => '10',
        $age < 55 => '15',
        default => '18',
    };
    $coordinated = '0';

    if (bccomp($annualSalary, '22680', 2) >= 0) {
        $capped = bccomp($annualSalary, '90720', 2) > 0 ? '90720' : $annualSalary;
        $coordinated = bcsub($capped, '26460', 2);
        $coordinated = bccomp($coordinated, '3780', 2) < 0 ? '3780' : $coordinated;
    }

    $bvgEmployee = bcdiv(bcdiv(bcdiv(bcmul($coordinated, $credit, 6), '100', 6), '12', 6), '2', 6);

    return [
        'ahv' => refFiveCentimes(bcdiv(bcmul($monthly, '5.3', 6), '100', 6)),
        'alv' => refFiveCentimes(bcdiv(bcmul($alvBasis, '1.1', 6), '100', 6)),
        'coordinated' => $coordinated,
        'bvg' => refFiveCentimes($bvgEmployee),
    ];
}

function refSwissContext(string $annualSalary, string $birth, string $gender = 'M', int $month = 1): SwissPayrollContext
{
    return new SwissPayrollContext(
        employee: new EmployeeData(
            hireDate: PayrollDates::date('2015-01-01'),
            dateOfBirth: PayrollDates::date($birth),
            gender: $gender,
            fullName: 'Mario Rossi',
            baseAnnualSalary: $annualSalary,
            salaryInstallmentsPerYear: 12,
            workCanton: 'TI',
        ),
        year: 2026,
        month: $month,
        wageTypes: SwissWageTypeCatalog::byCode(),
        settings: new SwissPayrollSettingsData,
    );
}

dataset('swiss monthly scenarios', [
    'CH1 CHF 120,000 age 40' => ['120000', '1986-05-10'],
    'CH2 CHF 84,500 age 40' => ['84500', '1986-05-10'],
    'CH3 CHF 100,000 age 30' => ['100000', '1996-09-01'],
    'CH4 CHF 72,000 age 50' => ['72000', '1976-02-02'],
    'CH5 CHF 60,000 age 58' => ['60000', '1968-12-12'],
    'CH6 CHF 20,000 below the BVG entry threshold' => ['20000', '1986-05-10'],
    'CH7 CHF 90,720 at the BVG maximum' => ['90720', '1986-05-10'],
    'CH8 CHF 180,000 above the ALV ceiling' => ['180000', '1986-05-10'],
    'CH9 CHF 30,000 at the minimum coordinated salary' => ['30000', '1986-05-10'],
]);

it('computes the Swiss AHV, ALV and BVG of a regular month like the hand reference', function (string $annualSalary, string $birth): void {
    $reference = refSwissMonth($annualSalary, (int) substr($birth, 0, 4));
    $result = SwissPayrollEngine::calculate(refSwissContext($annualSalary, $birth));
    $bvg = $result->amount(ChCodes::Bvg);

    expect(Dec::cmp($result->amount(ChCodes::Ahv), '-'.$reference['ahv']))->toBe(0, "AHV expected -{$reference['ahv']}, got {$result->amount(ChCodes::Ahv)}")
        ->and(Dec::cmp($result->amount(ChCodes::Alv), '-'.$reference['alv']))->toBe(0, "ALV expected -{$reference['alv']}, got {$result->amount(ChCodes::Alv)}")
        ->and(Dec::cmp($result->payslip->bvgInsuredSalary, $reference['coordinated']))->toBe(0, "BVG salary expected {$reference['coordinated']}, got {$result->payslip->bvgInsuredSalary}")
        ->and(Dec::cmp($bvg, '-'.$reference['bvg']))->toBe(0, "BVG expected -{$reference['bvg']}, got {$bvg}");
})->with('swiss monthly scenarios');

it('applies the AHV allowance and exempts ALV and BVG past the reference age, like the hand reference', function (): void {
    // Man born 1960-01-01: reference age 65 reached 2025-01-01. CHF 60,000 / 12 = 5,000; allowance 1,400: AHV basis 3,600 x 5.3% = 190.80.
    $result = SwissPayrollEngine::calculate(refSwissContext('60000', '1960-01-01'));

    expect(Dec::cmp($result->amount(ChCodes::Ahv), '-190.80'))->toBe(0)
        ->and(Dec::cmp($result->amount(ChCodes::Alv), '0'))->toBe(0)
        ->and(Dec::cmp($result->amount(ChCodes::Bvg), '0'))->toBe(0);
});

it('stops AHV and ALV for a person not yet in the contribution year, like the hand reference', function (): void {
    // Born 2009: 17 in 2026, contributions from 1 January 2027.
    $result = SwissPayrollEngine::calculate(refSwissContext('24000', '2009-06-01'));

    expect(Dec::cmp($result->amount(ChCodes::Ahv), '0'))->toBe(0)
        ->and(Dec::cmp($result->amount(ChCodes::Alv), '0'))->toBe(0);
});

it('pays the Swiss 13th salary in December on the paid days of the year', function (): void {
    // 78,000 / 13 = 6,000 a month; 360 paid days of the year: 13th = 6,000 x 360 / 360.
    $employee = new EmployeeData(hireDate: PayrollDates::date('2015-01-01'), dateOfBirth: PayrollDates::date('1986-05-10'), gender: 'M', fullName: 'Mario Rossi', baseAnnualSalary: '78000', salaryInstallmentsPerYear: 13, workCanton: 'TI');
    $result = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $employee,
        year: 2026,
        month: 12,
        wageTypes: SwissWageTypeCatalog::byCode(),
        settings: new SwissPayrollSettingsData,
        yearToDate: new SwissPayrollYearToDate('330', '66000', '66000'),
    ));

    expect(Dec::cmp($result->amount(ChCodes::ThirteenthSalary), '6000'))->toBe(0)
        ->and(Dec::cmp($result->payslip->grossSalary, '12000'))->toBe(0);
});
