<?php

declare(strict_types=1);

use Modules\HumanResources\DTOs\Payroll\PayslipLineData;
use Modules\HumanResources\DTOs\Payroll\WithholdingTariffData;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;
use Modules\HumanResources\Exceptions\Payroll\MissingWithholdingTariffException;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;
use Modules\HumanResources\Services\Payroll\SwissPayrollCalculator;
use Tests\Unit\HumanResources\Payroll\CalculatorFixtures;

beforeEach(function (): void {
    $this->calculator = new SwissPayrollCalculator;
});

test('standard monthly payslip yields Rappen-exact employee and employer lines that reconcile to the net', function (): void {
    $result = $this->calculator->calculate(CalculatorFixtures::input(), CalculatorFixtures::rateSet());

    expect($result->daysWorked)->toBe(30)
        ->and($result->grossRappen)->toBe(800_000)
        // Employee side
        ->and($result->amountOf('AHV_IV_EO', 'employee'))->toBe(42_400)
        ->and($result->amountOf('ALV', 'employee'))->toBe(8_800)
        ->and($result->amountOf('BVG', 'employee'))->toBe(26_775)
        ->and($result->amountOf('UVG_NBU', 'employee'))->toBe(9_600)
        ->and($result->amountOf('KTG', 'employee'))->toBe(4_000)
        // Employer side
        ->and($result->amountOf('AHV_IV_EO', 'employer'))->toBe(42_400)
        ->and($result->amountOf('AHV_ADMIN', 'employer'))->toBe(1_200)
        ->and($result->amountOf('FAK', 'employer'))->toBe(8_200)
        ->and($result->amountOf('ALV', 'employer'))->toBe(8_800)
        ->and($result->amountOf('BVG', 'employer'))->toBe(26_775)
        ->and($result->amountOf('UVG_BU', 'employer'))->toBe(800)
        ->and($result->amountOf('KTG', 'employer'))->toBe(4_000)
        // Totals
        ->and($result->employeeDeductionsRappen)->toBe(91_575)
        ->and($result->employerContributionsRappen)->toBe(92_175)
        ->and($result->netRappen)->toBe(708_425)
        ->and($result->line('ALV_SOLIDARITY'))->toBeNull()
        ->and($result->withholdingTaxRappen)->toBe(0);

    $employeeLines = array_sum(array_map(
        static fn (PayslipLineData $line): int => $line->isEmployeeDeduction() ? $line->amountRappen : 0,
        $result->lines,
    ));
    $employerLines = array_sum(array_map(
        static fn (PayslipLineData $line): int => $line->isEmployerContribution() ? $line->amountRappen : 0,
        $result->lines,
    ));

    expect($result->grossRappen - $employeeLines)->toBe($result->netRappen)
        ->and($employerLines)->toBe($result->employerContributionsRappen);
});

test('ALV is charged only up to the pro-rated ceiling and no solidarity line exists while its rate is zero', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['annualSalaryRappen' => 18_000_000]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->grossRappen)->toBe(1_500_000)
        ->and($result->alvBaseRappen)->toBe(1_235_000)
        ->and($result->alvSolidarityBaseRappen)->toBe(265_000)
        ->and($result->amountOf('ALV', 'employee'))->toBe(13_585)
        ->and($result->amountOf('ALV', 'employer'))->toBe(13_585)
        ->and($result->line('ALV_SOLIDARITY'))->toBeNull()
        ->and($result->uvgBaseRappen)->toBe(1_235_000)
        ->and($result->amountOf('UVG_NBU', 'employee'))->toBe(14_820);
});

test('ALV solidarity contribution applies above the ceiling when the rate set value is greater than zero', function (): void {
    $rateSet = CalculatorFixtures::rateSet([
        'alv.solidarity_employee' => '0.50',
        'alv.solidarity_employer' => '0.50',
    ]);

    $result = $this->calculator->calculate(CalculatorFixtures::input(['annualSalaryRappen' => 18_000_000]), $rateSet);

    expect($result->amountOf('ALV_SOLIDARITY', 'employee'))->toBe(1_325)
        ->and($result->amountOf('ALV_SOLIDARITY', 'employer'))->toBe(1_325)
        ->and($result->line('ALV_SOLIDARITY', 'employee')?->baseRappen)->toBe(265_000);
});

test('BVG is not charged below the entry threshold', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['annualSalaryRappen' => 2_000_000]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->line('BVG'))->toBeNull()
        ->and($result->bvgInsuredSalaryRappen)->toBe(0);
});

test('BVG insures the coordinated salary capped at the upper limit', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['annualSalaryRappen' => 18_000_000]),
        CalculatorFixtures::rateSet(),
    );

    // min(180'000, 90'720) - 26'460 = 64'260 per year, 5'355 per month
    expect($result->bvgInsuredSalaryRappen)->toBe(6_426_000)
        ->and($result->line('BVG', 'employee')?->baseRappen)->toBe(535_500)
        ->and($result->amountOf('BVG', 'employee'))->toBe(26_775);
});

test('BVG applies the minimum coordinated salary just above the entry threshold', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['annualSalaryRappen' => 2_500_000]),
        CalculatorFixtures::rateSet(),
    );

    // 25'000 - 26'460 < 3'780 -> 3'780 per year, 315 per month, 5% employee share at age 40
    expect($result->bvgInsuredSalaryRappen)->toBe(378_000)
        ->and($result->amountOf('BVG', 'employee'))->toBe(1_575);
});

test('BVG credit rate follows the age bands of the rate set', function (string $birthDate, ?int $expectedEmployeeShare): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['birthDate' => new DateTimeImmutable($birthDate)]),
        CalculatorFixtures::rateSet(),
    );

    if ($expectedEmployeeShare === null) {
        expect($result->line('BVG'))->toBeNull();

        return;
    }

    expect($result->amountOf('BVG', 'employee'))->toBe($expectedEmployeeShare)
        ->and($result->amountOf('BVG', 'employer'))->toBe($expectedEmployeeShare);
})->with([
    'age 22, risk only (no credits)' => ['2004-06-01', null],
    'age 30, 7%' => ['1996-06-01', 18_745],
    'age 40, 10%' => ['1986-06-01', 26_775],
    'age 50, 15%' => ['1976-06-01', 40_165],
    'age 60, 18%' => ['1966-06-01', 48_195],
]);

test('deductions are rounded to 0.05 CHF', function (): void {
    // 1'234.56 per month: AHV 5.3% = 65.43168 -> 65.45, ALV 1.1% = 13.58016 -> 13.60
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['annualSalaryRappen' => 1_481_472, 'uvgClass' => null, 'ktgInsured' => false]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->grossRappen)->toBe(123_456)
        ->and($result->amountOf('AHV_IV_EO', 'employee'))->toBe(6_545)
        ->and($result->amountOf('ALV', 'employee'))->toBe(1_360)
        ->and($result->line('BVG'))->toBeNull();

    foreach ($result->lines as $line) {
        if ($line->isEmployeeDeduction() || $line->isEmployerContribution()) {
            expect($line->amountRappen % 5)->toBe(0);
        }
    }
});

test('part-time mid-month entry is pro-rated with the 30-day convention', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input([
            'fteBasisPoints' => 6_000,
            'employmentStart' => new DateTimeImmutable('2026-01-16'),
        ]),
        CalculatorFixtures::rateSet(),
    );

    // 96'000 x 60% / 12 x 15 / 30 = 2'400.00
    expect($result->daysWorked)->toBe(15)
        ->and($result->grossRappen)->toBe(240_000)
        ->and($result->amountOf('AHV_IV_EO', 'employee'))->toBe(12_720)
        // BVG: (57'600 - 26'460) x 15 / 360 = 1'297.50 x 5% = 64.875 -> 64.90
        ->and($result->line('BVG', 'employee')?->baseRappen)->toBe(129_750)
        ->and($result->amountOf('BVG', 'employee'))->toBe(6_490);
});

test('30-day convention handles exits, short months and entries on the 31st', function (string $periodStart, string $periodEnd, string $start, ?string $end, int $expectedDays): void {
    $days = $this->calculator->daysWorked(CalculatorFixtures::input([
        'periodStart' => new DateTimeImmutable($periodStart),
        'periodEnd' => new DateTimeImmutable($periodEnd),
        'employmentStart' => new DateTimeImmutable($start),
        'employmentEnd' => $end !== null ? new DateTimeImmutable($end) : null,
    ]));

    expect($days)->toBe($expectedDays);
})->with([
    'full month' => ['2026-01-01', '2026-01-31', '2020-01-01', null, 30],
    'exit on the 10th' => ['2026-01-01', '2026-01-31', '2020-01-01', '2026-01-10', 10],
    'entry on 15 February' => ['2026-02-01', '2026-02-28', '2026-02-15', null, 16],
    'exit at the end of February' => ['2026-02-01', '2026-02-28', '2020-01-01', '2026-02-28', 30],
    'entry on the 31st' => ['2026-01-01', '2026-01-31', '2026-01-31', null, 1],
    'not yet employed' => ['2026-01-01', '2026-01-31', '2026-02-01', null, 0],
]);

test('withholding tax is looked up in the bracket covering the taxable salary', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['workCanton' => 'TI', 'withholdingTariff' => CalculatorFixtures::tariff()]),
        CalculatorFixtures::rateSet(),
    );

    // 8'000 falls in the 9.5% bracket: 760.00
    expect($result->qstBaseRappen)->toBe(800_000)
        ->and($result->withholdingTaxRappen)->toBe(76_000)
        ->and($result->amountOf('QST', 'employee'))->toBe(76_000)
        ->and($result->qstCanton)->toBe('TI')
        ->and($result->qstTariffCode)->toBe('A0N')
        ->and($result->amountOf('FAK', 'employer'))->toBe(12_000)
        ->and($result->netRappen)->toBe(800_000 - $result->employeeDeductionsRappen)
        ->and($result->employeeDeductionsRappen)->toBe(91_575 + 76_000);
});

test('withholding tax without a bracket for the salary is rejected', function (): void {
    $tariff = new WithholdingTariffData('TI', 'A0N', '2026-01-01', [
        ['income_from_rappen' => 0, 'income_to_rappen' => 500_000, 'rate_ppm' => 50_000, 'fixed_amount_rappen' => 0],
    ]);

    $this->calculator->calculate(CalculatorFixtures::input(['withholdingTariff' => $tariff]), CalculatorFixtures::rateSet());
})->throws(MissingWithholdingTariffException::class);

test('employees above the reference age get the retiree allowance and no ALV or BVG', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['birthDate' => new DateTimeImmutable('1960-05-10')]),
        CalculatorFixtures::rateSet(),
    );

    // AHV base 8'000 - 1'400 = 6'600 -> 5.3% = 349.80
    expect($result->ahvBaseRappen)->toBe(660_000)
        ->and($result->amountOf('AHV_IV_EO', 'employee'))->toBe(34_980)
        ->and($result->line('ALV'))->toBeNull()
        ->and($result->line('BVG'))->toBeNull()
        ->and($result->amountOf('UVG_NBU', 'employee'))->toBe(9_600);
});

test('employees reaching the reference age during the period still contribute that month', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['birthDate' => new DateTimeImmutable('1961-01-20')]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->ahvBaseRappen)->toBe(800_000)
        ->and($result->line('ALV', 'employee'))->not->toBeNull();
});

test('minors do not contribute to AHV and ALV before 1 January following their 17th birthday', function (): void {
    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['birthDate' => new DateTimeImmutable('2010-04-01'), 'annualSalaryRappen' => 1_200_000]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->line('AHV_IV_EO'))->toBeNull()
        ->and($result->line('ALV'))->toBeNull()
        ->and($result->netRappen)->toBe($result->grossRappen - $result->employeeDeductionsRappen);
});

test('wage type categories drive gross and net: benefits in kind, expenses and net deductions', function (): void {
    $benefit = CalculatorFixtures::wageType('2000', WageTypeCategory::BenefitInKind);
    $expense = CalculatorFixtures::wageType('6000', WageTypeCategory::Expense, subjectToInsurances: false);
    $parking = CalculatorFixtures::wageType('7000', WageTypeCategory::NetDeduction, subjectToInsurances: false);

    $result = $this->calculator->calculate(
        CalculatorFixtures::input(['earnings' => [
            CalculatorFixtures::earning($benefit, 30_000),
            CalculatorFixtures::earning($expense, 20_000),
            CalculatorFixtures::earning($parking, 5_000),
        ]]),
        CalculatorFixtures::rateSet(),
    );

    expect($result->grossRappen)->toBe(830_000)
        ->and($result->ahvBaseRappen)->toBe(830_000)
        ->and($result->line('7000')?->amountRappen)->toBe(-5_000)
        ->and($result->netRappen)->toBe(830_000 - $result->employeeDeductionsRappen - 30_000 + 20_000 - 5_000);
});

test('an unknown UVG class is rejected', function (): void {
    $this->calculator->calculate(CalculatorFixtures::input(['uvgClass' => 'X9']), CalculatorFixtures::rateSet());
})->throws(PayrollCalculationException::class);

test('the birth date is required for BVG insured employees', function (): void {
    $this->calculator->calculate(CalculatorFixtures::input(['birthDate' => null]), CalculatorFixtures::rateSet());
})->throws(PayrollCalculationException::class);
