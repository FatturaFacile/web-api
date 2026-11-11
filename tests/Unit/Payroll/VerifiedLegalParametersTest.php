<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollRules;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;

/**
 * The statutory values of the payroll defaults, each checked against an official document read on 2026-11-11. Only what
 * was verified is asserted here.
 *
 * ITALY
 * - IRPEF 2026: 23% to 28,000, 33% to 50,000 (the second rate cut from 35% by the 2026 budget law), 43% above: MEF, "Principali
 *   misure della legge di bilancio 2026" (tax at 50,000 = 13,700).
 * - Work income deduction art. 13 TUIR: 1,955 up to 15,000 (made structural by D.Lgs. 192/2024), 1,910 + 1,190 x (28,000 - R) / 13,000
 *   up to 28,000, 1,910 x (50,000 - R) / 22,000 up to 50,000, none above: Agenzia delle Entrate circolare 4/E of 16 May 2025,
 *   scheme on page 4. The increase of EUR 65 for income above 25,000 and up to 35,000 (art. 13 c. 1.1 TUIR) is in circolare 4/E of
 *   18 February 2022, and circolare 4/E 2025 states that the other provisions of art. 13 remain in force.
 * - Additional deduction of L. 207/2024 (budget law 2025, c. 6): 1,000 above 20,000 and up to 32,000, then 1,000 x (40,000 - R) / 8,000
 *   down to zero at 40,000: circolare 4/E 2025, pages 11-12 (a tax deduction, prorated to the period of work, which the engine does not).
 * - Non-taxable sum of L. 207/2024 (c. 4): for employees with total income up to 20,000, 7.1% of the employment income up to 8,500,
 *   5.3% up to 15,000, 4.8% above; the rate is chosen on the income of the whole year: circolare 4/E 2025, pages 9-10.
 * - INPS: additional 1% on the part of the pay above the first pensionable bracket of EUR 56,224 for 2026: INPS circolare n. 6 of 30 January 2026.
 * - Withholding tax of the self-employed: F24 tax code 1040, paid by the 16th of the following month (Agenzia delle Entrate, F24
 *   ritenute lavoro autonomo; F24/770 2026 table); config/fiscal_it.php.
 * - Agent commissions (art. 25-bis D.P.R. 600/1973): withholding on 50% of the commissions, 20% when the agent declares that it
 *   uses employees or third parties continuously, at the rate of the first IRPEF bracket (23% since 2024). These are inputs of the invoice
 *   (withholding_tax_percent and withholding_tax_base_percent), not constants: the arithmetic is covered in ReferenceItalianInvoiceTaxTest.
 * - Enasarco: contribution 17% (14% pension plus 3% solidarity) "dal 2020 a oggi", paid by the principal who may withhold the agent half;
 *   ceilings 2026 EUR 45,717 (single mandate) and 30,478 (multiple mandates): Fondazione Enasarco pages "Come si calcola il contributo
 *   previdenziale" (undated page) and "Minimali e massimali 2026". The rate is a per-tenant parameter row, not a constant.
 *
 * SWITZERLAND
 * - AHV/IV/EO employee 5.3% and ALV employee 1.1% up to the insured earnings of CHF 148,200 (2026): ahv-iv.ch and SECO.
 * - BVG 2026: entry threshold 22,680 (since 2025), coordination deduction 26,460, upper limit 90,720, minimum coordinated salary 3,780,
 *   age credits 7% (25-34), 10% (35-44), 15% (45-54), 18% (55-65): BSV, "Ratgeber Sozialversicherungen fuer KMU 2026", pages on the BVG.
 * - Allowance of CHF 16,800 a year (1,400 a month) on the AHV earnings of people past the reference age: same BSV guide.
 * - Family allowance minimum income CHF 630 a month (7,560 a year): same BSV guide, chapter Familienzulagen.
 *
 * STILL UNVERIFIED (not asserted): INPS employee rate 9.19% (it depends on the employer), TFR divisor 13.5 and 0.50% solidarity
 * contribution, the per-month proration of the deductions for part-year work, the BVG rounding of the contribution to 5 centimes.
 */
test('the Italian defaults are the 2026 verified figures', function (): void {
    $p = new ItalianPayrollParameters;

    expect([$p->irpefFirstLimit, $p->irpefSecondLimit, $p->irpefFirstRate, $p->irpefSecondRate, $p->irpefThirdRate])->toBe(['28000', '50000', '0.23', '0.33', '0.43'])
        ->and(Dec::cmp($p->grossIrpef('50000'), '13700'))->toBe(0)
        ->and([$p->workDeductionMaximum, $p->workDeductionFirstLimit, $p->workDeductionBase, $p->workDeductionSpread, $p->workDeductionSpreadRange, $p->workDeductionTaperRange])->toBe(['1955', '15000', '1910', '1190', '13000', '22000'])
        ->and([$p->workDeductionIncrease, $p->workDeductionIncreaseFrom, $p->workDeductionIncreaseTo])->toBe(['65', '25000', '35000'])
        ->and([$p->wedgeDeductionFrom, $p->wedgeDeductionFullTo, $p->wedgeDeductionEnd, $p->wedgeDeductionAmount, $p->wedgeDeductionTaperRange])->toBe(['20000', '32000', '40000', '1000', '8000'])
        ->and([$p->wedgeBonusFirstLimit, $p->wedgeBonusSecondLimit, $p->wedgeBonusThirdLimit, $p->wedgeBonusFirstRate, $p->wedgeBonusSecondRate, $p->wedgeBonusThirdRate])->toBe(['8500', '15000', '20000', '0.071', '0.053', '0.048'])
        ->and([$p->inpsFirstBracket, $p->inpsAdditionalRate])->toBe(['56224', '0.01']);
});

test('the work deduction and the additional deduction follow the circular at the boundaries', function (string $income, string $workDeduction, string $wedgeDeduction): void {
    $p = new ItalianPayrollParameters;

    expect(Dec::round($p->workDeduction($income)))->toBe($workDeduction)
        ->and(Dec::round($p->taxWedgeDeduction($income)))->toBe($wedgeDeduction);
})->with([
    'up to 15,000' => ['15000', '1955.00', '0.00'],
    '20,000: 1,910 + 1,190 x 8,000 / 13,000, no additional deduction yet' => ['20000', '2642.31', '0.00'],
    '25,000 is not above 25,000: no increase; 1,910 + 1,190 x 3,000 / 13,000' => ['25000', '2184.62', '1000.00'],
    '28,000: 1,910 + 65 (25,000 to 35,000)' => ['28000', '1975.00', '1000.00'],
    '32,000: 1,910 x 18,000 / 22,000 + 65' => ['32000', '1627.73', '1000.00'],
    '36,000: 1,910 x 14,000 / 22,000, no increase above 35,000; additional 1,000 x 4,000 / 8,000' => ['36000', '1215.45', '500.00'],
    '40,000: 1,910 x 10,000 / 22,000; additional deduction 0' => ['40000', '868.18', '0.00'],
    '50,000: nothing' => ['50000', '0.00', '0.00'],
]);

test('the non-taxable sum has the verified percentages and thresholds', function (string $income, string $expected): void {
    expect(Dec::cmp((new ItalianPayrollParameters)->taxWedgeBonus($income), $expected))->toBe(0);
})->with([
    'up to 8,500 at 7.1%' => ['8500', '603.5'],
    'above 8,500 at 5.3%' => ['10000', '530'],
    'up to 15,000 at 5.3%' => ['15000', '795'],
    'above 15,000 at 4.8%' => ['18000', '864'],
    'up to 20,000 at 4.8%' => ['20000', '960'],
    'above 20,000 nothing' => ['20000.01', '0'],
]);

test('the Swiss defaults are the 2026 verified figures', function (): void {
    $parameters = new SwissPayrollParameters;
    $settings = new SwissPayrollSettingsData;

    expect([$parameters->bvgEntryThreshold, $parameters->bvgCoordinationDeduction, $parameters->bvgMaximumSalary, $parameters->bvgMinimumCoordinatedSalary])->toBe(['22680', '26460', '90720', '3780'])
        ->and([$parameters->ahvEmployeeRate, $parameters->alvEmployeeRate, $parameters->alvMaxAnnualSalary])->toBe(['0.053', '0.011', '148200'])
        ->and($parameters->lafamMinimumMonthlyIncome)->toBe('630')
        ->and([$settings->ahvEmployeeRatePercent, $settings->ahvEmployerRatePercent, $settings->alvEmployeeRatePercent, $settings->alvEmployerRatePercent, $settings->alvAnnualCeiling, $settings->pensionerAllowanceMonthly])->toBe(['5.3', '5.3', '1.1', '1.1', '148200', '1400'])
        ->and(bcmul($settings->pensionerAllowanceMonthly, '12', 0))->toBe('16800');
});

test('the BVG coordinated salary follows the BSV limits', function (string $salary, string $coordinated): void {
    expect(Dec::cmp(SwissPayrollRules::bvgCoordinatedSalary($salary), $coordinated))->toBe(0);
})->with([
    'between the entry threshold and 30,240 the insured salary is 3,780' => ['22680', '3780'],
    '30,240 still 3,780' => ['30240', '3780'],
    'above: salary minus the coordination deduction' => ['40000', '13540'],
    'at the upper limit' => ['90720', '64260'],
    'above the upper limit the salary is capped' => ['150000', '64260'],
]);

test('the BVG age credits are 7, 10, 15 and 18 percent by age bracket, none below 25', function (string $birth, string $percent): void {
    expect(SwissPayrollRules::bvgAgeCreditPercent(CarbonImmutable::parse($birth)->toDateTimeImmutable(), 2026))->toBe($percent);
})->with([
    'age 24' => ['2002-06-01', '0'],
    'age 25' => ['2001-06-01', '7'],
    'age 34' => ['1992-06-01', '7'],
    'age 35' => ['1991-06-01', '10'],
    'age 44' => ['1982-06-01', '10'],
    'age 45' => ['1981-06-01', '15'],
    'age 54' => ['1972-06-01', '15'],
    'age 55' => ['1971-06-01', '18'],
    'age 65' => ['1961-06-01', '18'],
]);

test('the withholding tax of the self-employed goes to F24 code 1040 by the 16th of the month after', function (): void {
    expect(config('fiscal_it.withholding.f24_code'))->toBe('1040')
        ->and(config('fiscal_it.withholding.due_day'))->toBe(16);
});
