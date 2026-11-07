<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollResult;
use Modules\Payroll\Calculation\PayslipData;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\WageTypeData;
use Modules\Payroll\Calculation\WageTypeKind;

/**
 * The state of one Swiss calculation.
 */
final class SwissPayrollCalculation
{
    private const string DaysPerMonth = '30';

    private const string DaysPerYear = '360';

    /** @var list<PayslipLineData> */
    private array $lines = [];

    /** @var list<string> */
    private array $warnings;

    private readonly EmployeeData $employee;

    private readonly SwissPayrollSettingsData $settings;

    private readonly DateTimeImmutable $periodStart;

    private readonly DateTimeImmutable $periodEnd;

    private string $ahvBasis = '0';

    private string $alvBasis = '0';

    private string $uvgBasis = '0';

    private string $ktgBasis = '0';

    private string $bvgCoordinated = '0';

    private string $qstBasis = '0';

    private string $qstRate = '0';

    private ?string $qstCanton = null;

    public function __construct(private readonly SwissPayrollContext $context, private readonly string $paidDays)
    {
        $this->employee = $context->employee;
        $this->settings = $context->settings;
        $this->periodStart = PayrollDates::monthStart($context->year, $context->month);
        $this->periodEnd = PayrollDates::monthEnd($context->year, $context->month);
        $this->warnings = $context->parameterWarnings;
    }

    public function addEarnings(): void
    {
        if ($this->isHourly()) {
            return;
        }

        $monthly = $this->monthlySalary();
        $negativeMonthly = Dec::neg($monthly);

        if (Dec::cmp($this->paidDays, self::DaysPerMonth) >= 0) {
            $this->addLine(SwissWageTypeCodes::MonthlySalary, '1', $monthly, $monthly);
        } else {
            $this->addLine(
                SwissWageTypeCodes::MonthlySalary,
                $this->paidDays,
                Dec::div($monthly, self::DaysPerMonth),
                Dec::div(Dec::mul($monthly, $this->paidDays), self::DaysPerMonth),
            );
        }

        $unpaidDays = '0';

        foreach ($this->context->absences as $absence) {
            if (strcasecmp($absence->absenceType, 'Unpaid') === 0) {
                $unpaidDays = Dec::add($unpaidDays, SwissPayrollRules::absenceDays($this->context->year, $this->context->month, $absence->startDate, $absence->endDate, $absence->percent));
            }
        }

        if (Dec::isPositive($unpaidDays)) {
            $days = Dec::min($unpaidDays, $this->paidDays);
            $this->addLine(
                SwissWageTypeCodes::UnpaidLeave,
                $days,
                Dec::div($negativeMonthly, self::DaysPerMonth),
                Dec::div(Dec::mul($negativeMonthly, $days), self::DaysPerMonth),
            );
        }

        $termination = $this->employee->terminationDate;
        $exitMonth = $termination !== null
            && (int) $termination->format('Y') === $this->context->year
            && (int) $termination->format('n') === $this->context->month;

        if ($this->employee->salaryInstallmentsPerYear === 13 && ($this->context->month === 12 || $exitMonth)) {
            $yearDays = Dec::min(Dec::add($this->context->yearToDate->paidDays, $this->paidDays), self::DaysPerYear);
            $this->addLine(
                SwissWageTypeCodes::ThirteenthSalary,
                $yearDays,
                Dec::div($monthly, self::DaysPerYear),
                Dec::div(Dec::mul($monthly, $yearDays), self::DaysPerYear),
            );
        }
    }

    public function addInputs(): void
    {
        foreach ($this->context->inputs as $input) {
            $rate = $input->rate;

            if (Dec::isZero($rate) && $input->wageTypeCode === SwissWageTypeCodes::HourlyWage) {
                $rate = $this->employee->hourlyWage;
            }

            $amount = ! Dec::isZero($input->quantity) && ! Dec::isZero($rate)
                ? Dec::mul($input->quantity, $rate)
                : $input->amount;
            $this->addLine($input->wageTypeCode, $input->quantity, $rate, $amount, $input->note);
        }

        if (! $this->isHourly()) {
            return;
        }

        $hourlyTotal = Dec::sum(array_map(
            static fn (PayslipLineData $line): string => $line->amount,
            array_filter($this->lines, static fn (PayslipLineData $line): bool => $line->wageTypeCode === SwissWageTypeCodes::HourlyWage),
        ));
        $percent = SwissPayrollRules::holidayCompensationPercent($this->employee->holidayWeeks);

        if (! Dec::isZero($hourlyTotal) && Dec::isPositive($percent)) {
            $this->addLine(SwissWageTypeCodes::HolidayCompensation, $hourlyTotal, $percent, Dec::div(Dec::mul($hourlyTotal, $percent), '100'));
        }
    }

    public function addFamilyAllowances(): void
    {
        $canton = strtoupper($this->employee->workCanton ?? $this->settings->defaultWorkCanton);
        $rate = null;

        foreach ($this->context->familyAllowanceRates as $candidate) {
            if (strcasecmp($candidate->canton, $canton) === 0
                && $candidate->validFrom <= $this->periodEnd
                && ($rate === null || $candidate->validFrom > $rate->validFrom)) {
                $rate = $candidate;
            }
        }

        $children = 0;
        $education = 0;

        foreach ($this->context->children as $child) {
            $kind = SwissPayrollRules::familyAllowanceKind($child, $this->periodStart, $this->periodEnd);

            if ($kind === SwissPayrollRules::EducationAllowanceKind) {
                $education++;
            } elseif ($kind === SwissPayrollRules::ChildAllowanceKind) {
                $children++;
            }
        }

        if ($children + $education === 0) {
            return;
        }

        if ($rate === null) {
            $this->warnings[] = "Importi degli assegni familiari mancanti per il cantone {$canton}.";

            return;
        }

        $share = Dec::div($this->paidDays, self::DaysPerMonth);
        $this->addCountLine(SwissWageTypeCodes::ChildAllowance, $children, Dec::mul($rate->childAllowance, $share));
        $this->addCountLine(SwissWageTypeCodes::EducationAllowance, $education, Dec::mul($rate->educationAllowance, $share));
    }

    public function computeBases(): void
    {
        $birth = $this->employee->dateOfBirth;
        $contributionAge = SwissPayrollRules::isContributionAge($birth, $this->context->year);
        $pensioner = $birth !== null && SwissPayrollRules::referenceAgeDate($birth, $this->employee->gender) < $this->periodStart;

        $ahvRaw = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToAhv);
        $alvRaw = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToAlv);
        $uvgRaw = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToUvg);

        $this->ahvBasis = $contributionAge ? $ahvRaw : '0';

        if ($pensioner) {
            $allowance = Dec::div(Dec::mul($this->settings->pensionerAllowanceMonthly, $this->paidDays), self::DaysPerMonth);
            $this->ahvBasis = Dec::max('0', Dec::sub($this->ahvBasis, $allowance));
        }

        $yearDays = Dec::add($this->context->yearToDate->paidDays, $this->paidDays);
        $alvCeiling = Dec::sub(Dec::div(Dec::mul($this->settings->alvAnnualCeiling, $yearDays), self::DaysPerYear), $this->context->yearToDate->alvBasis);
        $uvgCeiling = Dec::sub(Dec::div(Dec::mul($this->settings->uvgAnnualCeiling, $yearDays), self::DaysPerYear), $this->context->yearToDate->uvgBasis);

        $this->alvBasis = $contributionAge && ! $pensioner ? self::clampToCeiling($alvRaw, $alvCeiling) : '0';
        $this->uvgBasis = self::clampToCeiling($uvgRaw, $uvgCeiling);
        $this->ktgBasis = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToKtg);
        $this->qstBasis = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToWithholdingTax);

        if (! $pensioner && ! $this->isShortFixedTerm()) {
            $annual = $this->isHourly()
                ? Dec::mul(Dec::mul($this->employee->hourlyWage, $this->employee->weeklyHours), '52')
                : $this->employee->baseAnnualSalary;
            $this->bvgCoordinated = SwissPayrollRules::bvgCoordinatedSalary($annual, $this->context->parameters);
        }
    }

    public function addContributions(): void
    {
        $nbuRate = self::rate($this->employee->nonOccupationalAccidentRatePercent, $this->settings->nbuEmployeeRatePercent);
        $buRate = self::rate($this->employee->occupationalAccidentRatePercent, $this->settings->buEmployerRatePercent);
        $ktgRate = self::rate($this->employee->dailySicknessRatePercent, $this->settings->ktgEmployeeRatePercent);
        $fakRate = self::rate($this->employee->familyAllowanceFundRatePercent, $this->settings->fakEmployerRatePercent);
        $nbuDue = ! $this->isHourly()
            || Dec::isZero($this->employee->weeklyHours)
            || Dec::cmp($this->employee->weeklyHours, $this->context->parameters->nbuMinimumWeeklyHours) >= 0;

        $this->addContribution(SwissWageTypeCodes::Ahv, $this->ahvBasis, $this->settings->ahvEmployeeRatePercent, false);
        $this->addContribution(SwissWageTypeCodes::Alv, $this->alvBasis, $this->settings->alvEmployeeRatePercent, false);
        $this->addContribution(SwissWageTypeCodes::Nbu, $nbuDue ? $this->uvgBasis : '0', $nbuRate, false);
        $this->addContribution(SwissWageTypeCodes::Ktg, $this->ktgBasis, $ktgRate, false);

        $ahvEmployer = $this->addContribution(SwissWageTypeCodes::AhvEmployer, $this->ahvBasis, $this->settings->ahvEmployerRatePercent, true);
        $ahvTotal = Dec::add($ahvEmployer, Dec::div(Dec::mul($this->ahvBasis, $this->settings->ahvEmployeeRatePercent), '100'));
        $this->addContribution(SwissWageTypeCodes::AhvAdministration, $ahvTotal, $this->settings->ahvAdministrationCostPercent, true);
        $this->addContribution(SwissWageTypeCodes::AlvEmployer, $this->alvBasis, $this->settings->alvEmployerRatePercent, true);
        $this->addContribution(SwissWageTypeCodes::BuEmployer, $this->uvgBasis, $buRate, true);
        $this->addContribution(SwissWageTypeCodes::KtgEmployer, $this->ktgBasis, $this->settings->ktgEmployerRatePercent, true);
        $this->addContribution(SwissWageTypeCodes::FakEmployer, $this->ahvBasis, $fakRate, true);

        $this->addPensionContributions();
    }

    public function addWithholdingTax(): void
    {
        if (! $this->employee->isWithholdingTaxSubject || ! Dec::isPositive($this->qstBasis)) {
            return;
        }

        $resident = strtoupper($this->employee->residenceCountry ?? 'CH') === 'CH';
        $this->qstCanton = ($resident ? $this->employee->residenceCanton ?? $this->employee->workCanton : $this->employee->workCanton)
            ?? $this->settings->defaultWorkCanton;

        $tariff = $this->findTariff($this->withholdingTaxRateIncome());
        $manualRate = $this->employee->withholdingTaxRatePercent;

        if ($tariff !== null) {
            $this->qstRate = $tariff->ratePercent;
            $tax = Dec::max(Dec::div(Dec::mul($this->qstBasis, $this->qstRate), '100'), $tariff->minimumTax);
        } elseif ($manualRate !== null) {
            $this->qstRate = $manualRate;
            $tax = Dec::div(Dec::mul($this->qstBasis, $manualRate), '100');
            $this->warnings[] = "Tariffa {$this->employee->withholdingTaxCode} del cantone {$this->qstCanton} non importata: usata l'aliquota manuale ".Dec::trimmed($manualRate).' %.';
        } else {
            $this->warnings[] = "Imposta alla fonte non calcolata: importare le tariffe ESTV del cantone {$this->qstCanton} (codice {$this->employee->withholdingTaxCode}).";

            return;
        }

        $this->addLine(SwissWageTypeCodes::WithholdingTax, $this->qstBasis, $this->qstRate, Dec::neg(SwissPayrollRules::roundTo5Centimes($tax)));
    }

    public function build(): PayrollResult
    {
        $ordered = $this->lines;
        usort($ordered, static fn (PayslipLineData $left, PayslipLineData $right): int => [$left->sortOrder, $left->wageTypeCode] <=> [$right->sortOrder, $right->wageTypeCode]);

        $gross = self::total($ordered, static fn (PayslipLineData $line): bool => WageTypeKind::isGross($line->kind));
        $deductions = Dec::neg(self::total($ordered, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Deduction));
        $nonCash = self::total($ordered, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::NonCash);
        $expenses = self::total($ordered, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Expense);
        $employer = self::total($ordered, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Employer);
        $net = Dec::sub($gross, $deductions);
        $subject = $this->employee->isWithholdingTaxSubject;

        return new PayrollResult(new PayslipData(
            employeeId: $this->employee->id,
            year: $this->context->year,
            month: $this->context->month,
            employeeName: $this->employee->fullName,
            paidDays: $this->paidDays,
            grossSalary: Dec::round($gross),
            ahvBasis: Dec::round($this->ahvBasis),
            alvBasis: Dec::round($this->alvBasis),
            uvgBasis: Dec::round($this->uvgBasis),
            ktgBasis: Dec::round($this->ktgBasis),
            bvgInsuredSalary: Dec::round($this->bvgCoordinated),
            withholdingTaxBasis: Dec::round($subject ? $this->qstBasis : '0'),
            withholdingTaxRatePercent: $this->qstRate,
            withholdingTaxCode: $subject ? $this->employee->withholdingTaxCode : null,
            withholdingTaxCanton: $this->qstCanton,
            employeeDeductions: Dec::round($deductions),
            netSalary: Dec::round($net),
            payoutAmount: SwissPayrollRules::roundTo5Centimes(Dec::add(Dec::sub($net, $nonCash), $expenses)),
            employerContributions: Dec::round($employer),
            warnings: $this->warnings === [] ? null : implode("\n", $this->warnings),
        ), $ordered);
    }

    private static function clampToCeiling(string $value, string $ceiling): string
    {
        return Dec::max('0', Dec::min($value, $ceiling));
    }

    private static function rate(string $employeeRate, string $companyRate): string
    {
        return Dec::isPositive($employeeRate) ? $employeeRate : $companyRate;
    }

    /**
     * @param  list<PayslipLineData>  $lines
     * @param  callable(PayslipLineData): bool  $filter
     */
    private static function total(array $lines, callable $filter): string
    {
        return Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, array_filter($lines, $filter)));
    }

    private function isHourly(): bool
    {
        return strcasecmp($this->employee->payType, 'hourly') === 0;
    }

    private function monthlySalary(): string
    {
        return $this->employee->salaryInstallmentsPerYear <= 0
            ? '0'
            : Dec::div($this->employee->baseAnnualSalary, (string) $this->employee->salaryInstallmentsPerYear);
    }

    private function isShortFixedTerm(): bool
    {
        $end = $this->employee->terminationDate;

        return $this->employee->isFixedTermContract
            && $end !== null
            && $end <= PayrollDates::addMonths($this->employee->hireDate, 3);
    }

    private function addPensionContributions(): void
    {
        $employerShare = Dec::max('50', Dec::min($this->settings->bvgEmployerSharePercent, '100'));
        $planRate = $this->employee->pensionFundEmployeeRatePercent;

        if ($planRate !== null && Dec::isPositive($planRate)) {
            $basis = $this->sumSubject(static fn (WageTypeData $type): bool => $type->subjectToBvg);
            $employeeContribution = Dec::div(Dec::mul($basis, $planRate), '100');
            $employerContribution = Dec::cmp($employerShare, '100') >= 0
                ? '0'
                : Dec::div(Dec::mul($employeeContribution, $employerShare), Dec::sub('100', $employerShare));
        } else {
            if (! Dec::isPositive($this->bvgCoordinated) || ! SwissPayrollRules::isContributionAge($this->employee->dateOfBirth, $this->context->year)) {
                return;
            }

            $percent = Dec::add(SwissPayrollRules::bvgAgeCreditPercent($this->employee->dateOfBirth, $this->context->year), $this->settings->bvgRiskRatePercent);
            $annualTotal = Dec::div(Dec::mul($this->bvgCoordinated, $percent), '100');
            $total = Dec::div(Dec::mul(Dec::div($annualTotal, '12'), $this->paidDays), self::DaysPerMonth);
            $employeeContribution = Dec::div(Dec::mul($total, Dec::sub('100', $employerShare)), '100');
            $employerContribution = Dec::sub($total, $employeeContribution);
        }

        $this->addLine(SwissWageTypeCodes::Bvg, $this->bvgCoordinated, '0', Dec::neg(SwissPayrollRules::roundTo5Centimes($employeeContribution)));
        $this->addLine(SwissWageTypeCodes::BvgEmployer, $this->bvgCoordinated, '0', SwissPayrollRules::roundTo5Centimes($employerContribution));
    }

    private function withholdingTaxRateIncome(): string
    {
        if (SwissPayrollRules::usesAnnualWithholdingTaxModel($this->qstCanton)) {
            // Annual model: the rate is determined on the regular monthly income including the pro-rata share of the 13th salary.
            $regular = '0';

            foreach ($this->lines as $line) {
                $type = $this->context->wageTypes[$line->wageTypeCode] ?? null;

                if ($type !== null && $type->subjectToWithholdingTax && ! $type->isIrregular && $line->wageTypeCode !== SwissWageTypeCodes::ThirteenthSalary) {
                    $regular = Dec::add($regular, $line->amount);
                }
            }

            $installments = max($this->employee->salaryInstallmentsPerYear, 12);

            return Dec::div(Dec::mul(Dec::div(Dec::mul($regular, (string) $installments), '12'), self::DaysPerMonth), $this->paidDays);
        }

        // Monthly model: the income of an incomplete month is converted to a full month to determine the rate.
        return Dec::div(Dec::mul($this->qstBasis, self::DaysPerMonth), $this->paidDays);
    }

    private function findTariff(string $rateIncome): ?WithholdingTaxTariffData
    {
        $code = strtoupper(trim($this->employee->withholdingTaxCode ?? ''));
        $rows = array_values(array_filter(
            $this->context->withholdingTaxTariffs,
            fn (WithholdingTaxTariffData $tariff): bool => strcasecmp($tariff->canton, (string) $this->qstCanton) === 0
                && strcasecmp($tariff->tariffCode, $code) === 0
                && $tariff->validFrom <= $this->periodEnd,
        ));

        if ($rows === []) {
            return null;
        }

        $validFrom = max(array_map(static fn (WithholdingTaxTariffData $tariff): DateTimeImmutable => $tariff->validFrom, $rows));
        $current = array_values(array_filter($rows, static fn (WithholdingTaxTariffData $tariff): bool => $tariff->validFrom == $validFrom));
        usort($current, static fn (WithholdingTaxTariffData $left, WithholdingTaxTariffData $right): int => Dec::cmp($left->incomeFrom, $right->incomeFrom));
        $match = null;

        foreach ($current as $tariff) {
            if (Dec::cmp($tariff->incomeFrom, $rateIncome) <= 0) {
                $match = $tariff;
            }
        }

        return $match ?? $current[0];
    }

    /**
     * @param  callable(WageTypeData): bool  $subject
     */
    private function sumSubject(callable $subject): string
    {
        $total = '0';

        foreach ($this->lines as $line) {
            $type = $this->context->wageTypes[$line->wageTypeCode] ?? null;

            if ($type !== null && WageTypeKind::isGross($type->kind) && $subject($type)) {
                $total = Dec::add($total, $line->amount);
            }
        }

        return $total;
    }

    private function addContribution(string $code, string $basis, string $ratePercent, bool $employer): string
    {
        if (! Dec::isPositive($basis) || ! Dec::isPositive($ratePercent)) {
            return '0';
        }

        $amount = SwissPayrollRules::roundTo5Centimes(Dec::div(Dec::mul($basis, $ratePercent), '100'));
        $this->addLine($code, $basis, $ratePercent, $employer ? $amount : Dec::neg($amount));

        return $amount;
    }

    private function addCountLine(string $code, int $count, string $amountEach): void
    {
        if ($count > 0) {
            $this->addLine($code, (string) $count, $amountEach, Dec::mul((string) $count, $amountEach));
        }
    }

    private function addLine(string $code, string $quantity, string $rate, string $amount, ?string $note = null): void
    {
        $type = $this->context->wageTypes[$code] ?? null;

        if ($type === null) {
            $this->warnings[] = "Genere salariale {$code} non definito: riga ignorata.";

            return;
        }

        $this->lines[] = new PayslipLineData(
            wageTypeCode: $code,
            description: trim((string) $note) === '' ? $type->name : "{$type->name} - {$note}",
            kind: $type->kind,
            quantity: Dec::round($quantity, 4),
            rate: Dec::round($rate, 4),
            amount: Dec::round($amount),
            sortOrder: $type->sortOrder,
            certificateBox: $type->certificateBox,
        );
    }
}
