<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Extras;

use App\Models\Tenant;
use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\Italian\ItalianPayrollPreview;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollPreview;
use Modules\Payroll\Calculation\Swiss\SwissPayrollPreview;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\PayrollParameterResolver;

/**
 * Gross-to-net and net-to-gross before hiring, on the country previews and without any record: the person does not exist
 * yet. Net-to-gross searches the salary, because a preview cannot be inverted: LPP starts at a threshold, so the net of a
 * salary is not continuous and an exact match may not exist. The result then says how far it stayed.
 */
final class HiringSimulationService
{
    private const int MaximumIterations = 64;

    /** Half a 5-centime step: closer than this the search stops. */
    private const string Tolerance = '0.025';

    public function __construct(private readonly PayrollParameterResolver $parameters) {}

    /**
     * @param  array{annual_gross?: string|int|float|null, target_net_monthly?: string|int|float|null, year: int, date_of_birth?: string|null, fte_ratio?: string|int|float|null, salary_installments_per_year?: int|null, withholding_tax_rate_percent?: string|int|float|null, pension_fund_employee_rate_percent?: string|int|float|null, residence_country?: string|null}  $input
     * @return array<string, mixed>
     */
    public function simulate(Tenant $tenant, array $input): array
    {
        $target = PayrollDates::monthStart($input['year'], 1);

        if (isset($input['annual_gross'])) {
            $preview = $this->preview($tenant, $input, Dec::of((string) $input['annual_gross']), $target);

            return self::result('gross_to_net', Dec::of((string) $input['annual_gross']), $preview, null, true);
        }

        return $this->netToGross($tenant, $input, Dec::of((string) $input['target_net_monthly']), $target);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function netToGross(Tenant $tenant, array $input, string $targetNet, DateTimeImmutable $target): array
    {
        $installments = (string) ($input['salary_installments_per_year'] ?? 12);
        $low = '0';
        $high = Dec::mul(Dec::mul($targetNet, $installments), '3');
        $best = null;
        $bestAnnual = '0';
        $bestDistance = null;

        for ($iteration = 0; $iteration < self::MaximumIterations; $iteration++) {
            $annual = Dec::round(Dec::div(Dec::add($low, $high), '2'));
            $preview = $this->preview($tenant, $input, $annual, $target);
            $difference = Dec::sub($preview->netMonthlySalary, $targetNet);
            $distance = Dec::isNegative($difference) ? Dec::neg($difference) : $difference;

            if ($bestDistance === null || Dec::cmp($distance, $bestDistance) < 0) {
                [$best, $bestAnnual, $bestDistance] = [$preview, $annual, $distance];
            }

            if (Dec::cmp($distance, self::Tolerance) <= 0) {
                break;
            }

            if (Dec::isNegative($difference)) {
                $low = $annual;
            } else {
                $high = $annual;
            }

            if (Dec::cmp(Dec::sub($high, $low), '0.01') <= 0) {
                break;
            }
        }

        return self::result('net_to_gross', $bestAnnual, $best, $targetNet, Dec::cmp($bestDistance, '0.05') <= 0);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function preview(Tenant $tenant, array $input, string $annualGross, DateTimeImmutable $target): PayrollPreview
    {
        $settings = SwissPayrollSettings::query()->first();
        $withholdingRate = $input['withholding_tax_rate_percent'] ?? null;
        $pensionRate = $input['pension_fund_employee_rate_percent'] ?? null;

        $employee = new EmployeeData(
            hireDate: $target,
            dateOfBirth: isset($input['date_of_birth']) ? new DateTimeImmutable((string) $input['date_of_birth']) : null,
            baseAnnualSalary: $annualGross,
            fteRatio: (string) ($input['fte_ratio'] ?? '1'),
            salaryInstallmentsPerYear: (int) ($input['salary_installments_per_year'] ?? 12),
            residenceCountry: $input['residence_country'] ?? 'CH',
            isWithholdingTaxSubject: $withholdingRate !== null,
            withholdingTaxRatePercent: $withholdingRate === null ? null : (string) $withholdingRate,
            nonOccupationalAccidentRatePercent: (string) ($settings->nbu_employee_rate_percent ?? '0'),
            occupationalAccidentRatePercent: (string) ($settings->bu_employer_rate_percent ?? '0'),
            dailySicknessRatePercent: (string) ($settings->ktg_employee_rate_percent ?? '0'),
            familyAllowanceFundRatePercent: (string) ($settings->fak_employer_rate_percent ?? '0'),
            pensionFundEmployeeRatePercent: $pensionRate === null ? null : (string) $pensionRate,
        );

        return match (strtoupper((string) $tenant->country)) {
            'CH' => SwissPayrollPreview::calculate($employee, $target, $this->parameters->swiss((int) $target->format('Y'))[0]),
            'IT' => ItalianPayrollPreview::calculate($employee, $target, $this->parameters->italian((int) $target->format('Y'))[0]),
            default => throw new PayrollException('La simulazione è disponibile per le aziende svizzere e italiane.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string $direction, string $annualGross, PayrollPreview $preview, ?string $targetNet, bool $isExact): array
    {
        return [
            'direction' => $direction,
            'currency' => $preview->currency,
            'annual_gross' => Dec::round($annualGross),
            'monthly_gross' => $preview->grossMonthlySalary,
            'total_deductions' => $preview->totalDeductions,
            'monthly_net' => $preview->netMonthlySalary,
            'target_net_monthly' => $targetNet,
            'is_exact' => $isExact,
            'employer_total_cost' => $preview->employerTotalCost,
            'deductions' => $preview->deductionBreakdown,
            'notes' => $preview->complianceNotes,
        ];
    }
}
