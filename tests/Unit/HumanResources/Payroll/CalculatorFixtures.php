<?php

declare(strict_types=1);

namespace Tests\Unit\HumanResources\Payroll;

use DateTimeImmutable;
use Modules\HumanResources\DTOs\Payroll\EarningInput;
use Modules\HumanResources\DTOs\Payroll\PayslipCalculationInput;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\DTOs\Payroll\WageTypeData;
use Modules\HumanResources\DTOs\Payroll\WithholdingTariffData;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;

/**
 * Known, self-contained fixtures for the pure Swiss payroll calculator tests (independent from the seeder).
 */
final class CalculatorFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function rates(): array
    {
        return [
            'ahv_iv_eo' => ['employee' => '5.30', 'employer' => '5.30'],
            'ahv_admin_cost' => ['employer' => '0.15'],
            'ahv_contribution_start_age' => 17,
            'reference_age' => 65,
            'ahv_retiree_allowance_monthly' => '1400.00',
            'alv' => [
                'employee' => '1.10',
                'employer' => '1.10',
                'ceiling_annual' => '148200.00',
                'solidarity_employee' => '0.00',
                'solidarity_employer' => '0.00',
            ],
            'bvg' => [
                'entry_threshold' => '22680.00',
                'coordination_deduction' => '26460.00',
                'upper_limit' => '90720.00',
                'min_coordinated_salary' => '3780.00',
                'employer_share' => '50.00',
                'age_credits' => [
                    ['from_age' => 25, 'to_age' => 34, 'rate' => '7.00'],
                    ['from_age' => 35, 'to_age' => 44, 'rate' => '10.00'],
                    ['from_age' => 45, 'to_age' => 54, 'rate' => '15.00'],
                    ['from_age' => 55, 'to_age' => 65, 'rate' => '18.00'],
                ],
            ],
            'uvg' => [
                'max_insured_annual' => '148200.00',
                'classes' => ['A1' => ['bu' => '0.10', 'nbu' => '1.20']],
            ],
            'ktg' => ['employee' => '0.50', 'employer' => '0.50'],
            'fak' => ['default' => '1.20', 'cantons' => ['ZH' => '1.025', 'TI' => '1.50']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides  dotted paths
     */
    public static function rateSet(array $overrides = []): RateSet
    {
        $rates = self::rates();

        foreach ($overrides as $path => $value) {
            data_set($rates, $path, $value);
        }

        return RateSet::fromArray($rates, 'rate-set-fixture', 'CH-FIXTURE', 2026);
    }

    public static function wageType(
        string $code = '1000',
        WageTypeCategory $category = WageTypeCategory::Salary,
        bool $subjectToInsurances = true,
    ): WageTypeData {
        return new WageTypeData(
            id: 'wt-'.$code,
            code: $code,
            name: 'Wage type '.$code,
            category: $category,
            isAhvSubject: $subjectToInsurances,
            isAlvSubject: $subjectToInsurances,
            isBvgSubject: $subjectToInsurances,
            isUvgSubject: $subjectToInsurances,
            isQstSubject: $subjectToInsurances,
        );
    }

    /**
     * Standard case: CHF 96'000 per year (8'000 per month), 100%, born 1986 (BVG age 40), canton ZH, January 2026.
     *
     * @param  array<string, mixed>  $overrides  named constructor arguments of PayslipCalculationInput
     */
    public static function input(array $overrides = []): PayslipCalculationInput
    {
        $defaults = [
            'employeeId' => 'employee-1',
            'periodStart' => new DateTimeImmutable('2026-01-01'),
            'periodEnd' => new DateTimeImmutable('2026-01-31'),
            'employmentStart' => new DateTimeImmutable('2020-01-01'),
            'employmentEnd' => null,
            'birthDate' => new DateTimeImmutable('1986-03-10'),
            'annualSalaryRappen' => 9_600_000,
            'fteBasisPoints' => 10_000,
            'baseSalaryWageType' => self::wageType(),
            'workCanton' => 'ZH',
            'earnings' => [],
            'bvgInsured' => true,
            'uvgClass' => 'A1',
            'ktgInsured' => true,
            'withholdingTariff' => null,
        ];

        return new PayslipCalculationInput(...array_merge($defaults, $overrides));
    }

    public static function earning(WageTypeData $wageType, int $amountRappen): EarningInput
    {
        return new EarningInput($wageType, $amountRappen);
    }

    /**
     * TI A0N sample: up to 7'000 -> 5%, up to 9'000 -> 9.5%, above -> 12%.
     */
    public static function tariff(string $code = 'A0N'): WithholdingTariffData
    {
        return new WithholdingTariffData(
            canton: 'TI',
            tariffCode: $code,
            validFrom: '2026-01-01',
            brackets: [
                ['income_from_rappen' => 0, 'income_to_rappen' => 700_000, 'rate_ppm' => 50_000, 'fixed_amount_rappen' => 0],
                ['income_from_rappen' => 700_005, 'income_to_rappen' => 900_000, 'rate_ppm' => 95_000, 'fixed_amount_rappen' => 0],
                ['income_from_rappen' => 900_005, 'income_to_rappen' => null, 'rate_ppm' => 120_000, 'fixed_amount_rappen' => 0],
            ],
        );
    }
}
