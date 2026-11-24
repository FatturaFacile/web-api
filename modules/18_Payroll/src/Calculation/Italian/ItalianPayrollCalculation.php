<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollResult;
use Modules\Payroll\Calculation\PayslipData;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\WageTypeKind;

/**
 * The state of one Italian calculation.
 */
final class ItalianPayrollCalculation
{
    private const string ConventionalDays = '26';

    /** @var list<string> */
    private const array IndemnifiedAbsences = ['Sickness', 'Accident', 'Maternity'];

    /** @var list<PayslipLineData> */
    private array $lines = [];

    /** @var list<string> */
    private array $warnings;

    private readonly EmployeeData $employee;

    private readonly ItalianPayrollParameters $parameters;

    private string $paidDays = self::ConventionalDays;

    private string $monthly = '0';

    private string $inpsBasis = '0';

    private string $taxable = '0';

    public function __construct(
        private readonly ItalianPayrollContext $context,
        private readonly DateTimeImmutable $first,
        private readonly DateTimeImmutable $last,
    ) {
        $this->employee = $context->employee;
        $this->parameters = $context->parameters;
        $this->warnings = $context->parameterWarnings;
    }

    public function earnings(): void
    {
        $fte = Dec::isPositive($this->employee->fteRatio) ? $this->employee->fteRatio : '1';
        $this->monthly = Dec::round(Dec::div(Dec::mul($this->employee->baseAnnualSalary, $fte), (string) $this->installments()));
        $level = $this->context->level;

        if ($level !== null) {
            $minimum = Dec::mul($level->monthlyMinimum, $fte);

            if (Dec::cmp($this->monthly, Dec::round($minimum)) < 0) {
                $this->warnings[] = 'Retribuzione mensile '.Dec::round($this->monthly)." inferiore al minimo del livello {$level->level} (".Dec::round($minimum).').';
            }
        }

        $from = $this->employee->hireDate > $this->first ? $this->employee->hireDate : $this->first;
        $termination = $this->employee->terminationDate;
        $to = $termination !== null && $termination < $this->last ? $termination : $this->last;

        if ($from > $this->first || $to < $this->last) {
            $this->paidDays = Dec::min(self::ConventionalDays, (string) self::workingDays($from, $to));
            $this->add(
                ItalianWageCodes::Salary,
                'Retribuzione (giorni)',
                WageTypeKind::Earning,
                $this->paidDays,
                Dec::round(Dec::div($this->monthly, self::ConventionalDays)),
                Dec::round(Dec::div(Dec::mul($this->monthly, $this->paidDays), self::ConventionalDays)),
            );
        } else {
            $this->add(ItalianWageCodes::Salary, 'Retribuzione mensile', WageTypeKind::Earning, '1', $this->monthly, $this->monthly);
        }

        $unpaidDays = '0';

        foreach ($this->context->absences as $absence) {
            if ($absence->absenceType === 'Unpaid') {
                $absenceFrom = $absence->startDate < $this->first ? $this->first : $absence->startDate;
                $absenceTo = $absence->endDate > $this->last ? $this->last : $absence->endDate;
                $unpaidDays = Dec::add($unpaidDays, Dec::div(Dec::mul((string) self::workingDays($absenceFrom, $absenceTo), $absence->percent), '100'));
            }
        }

        if (Dec::isPositive($unpaidDays)) {
            $this->paidDays = Dec::max('0', Dec::sub($this->paidDays, $unpaidDays));
            $this->add(
                ItalianWageCodes::UnpaidAbsence,
                'Assenza non retribuita',
                WageTypeKind::Earning,
                $unpaidDays,
                Dec::neg(Dec::round(Dec::div($this->monthly, self::ConventionalDays))),
                Dec::neg(Dec::round(Dec::div(Dec::mul($this->monthly, $unpaidDays), self::ConventionalDays))),
            );
        }

        foreach ($this->context->absences as $absence) {
            if (in_array($absence->absenceType, self::IndemnifiedAbsences, true)) {
                $this->warnings[] = 'Malattia, infortunio o maternità nel mese: indennità INPS/INAIL e integrazioni del CCNL da inserire come voci variabili.';
                break;
            }
        }

        $this->additionalInstallments();

        foreach ($this->context->inputs as $input) {
            $amount = ! Dec::isZero($input->amount) ? $input->amount : Dec::round(Dec::mul($input->quantity, $input->rate));
            $description = trim((string) $input->note) === '' ? $input->wageTypeCode : (string) $input->note;
            $this->add($input->wageTypeCode, $description, WageTypeKind::Earning, $input->quantity, $input->rate, $amount);
        }
    }

    public function contributions(): void
    {
        $gross = $this->grossTotal();
        $parameters = $this->parameters;
        $this->inpsBasis = Dec::round($gross, 0);
        $rate = $this->context->settings?->employeeInpsRatePercent ?? Dec::mul($parameters->inpsEmployeeRate, '100');
        $previousBasis = Dec::sum(array_map(static fn (ItalianPreviousPayslip $payslip): string => $payslip->inpsBasis, $this->context->yearToDate));
        $above = Dec::sub(
            Dec::max('0', Dec::sub(Dec::add($previousBasis, $this->inpsBasis), $parameters->inpsFirstBracket)),
            Dec::max('0', Dec::sub($previousBasis, $parameters->inpsFirstBracket)),
        );
        $inpsEmployee = Dec::round(Dec::add(Dec::div(Dec::mul($this->inpsBasis, $rate), '100'), Dec::mul($above, $parameters->inpsAdditionalRate)));
        $this->add(ItalianWageCodes::InpsEmployee, 'Contributi INPS a carico del dipendente', WageTypeKind::Deduction, $this->inpsBasis, $rate, Dec::neg($inpsEmployee));
        $this->taxable = Dec::sub($gross, $inpsEmployee);
    }

    public function taxes(): void
    {
        $parameters = $this->parameters;
        $previousTaxable = Dec::sum(array_map(static fn (ItalianPreviousPayslip $payslip): string => $payslip->taxableIncome, $this->context->yearToDate));
        $previousIrpef = Dec::neg($this->yearToDate(ItalianWageCodes::Irpef));
        $previousWedge = $this->yearToDate(ItalianWageCodes::TaxWedge);

        if ($this->isYearEnd()) {
            $annual = Dec::add($previousTaxable, $this->taxable);
            $irpef = Dec::sub($this->annualIrpef($annual), $previousIrpef);
            $wedge = Dec::sub(Dec::round($parameters->taxWedgeBonus($annual)), $previousWedge);
            $this->warnings[] = 'Conguaglio IRPEF di fine anno o di cessazione applicato (senza detrazioni per familiari e altri redditi).';
        } else {
            $projected = Dec::mul($this->taxable, '12');
            $irpef = Dec::round(Dec::div($this->annualIrpef($projected), '12'));
            $wedge = Dec::round(Dec::div($parameters->taxWedgeBonus($projected), '12'));
        }

        $this->add(
            ItalianWageCodes::Irpef,
            Dec::cmp($irpef, '0') >= 0 ? 'Ritenuta IRPEF' : 'Rimborso IRPEF da conguaglio',
            WageTypeKind::Deduction,
            $this->taxable,
            '0',
            Dec::neg($irpef),
        );

        if (! Dec::isZero($wedge)) {
            $this->add(ItalianWageCodes::TaxWedge, 'Somma non imponibile cuneo fiscale', WageTypeKind::Expense, '1', '0', $wedge);
        }

        if ($this->context->month <= 11 && Dec::isPositive($this->context->previousYearTaxable)) {
            $previousYear = $this->context->previousYearTaxable;
            $regionalRate = $this->employee->regionalSurchargeRatePercent;
            $municipalRate = $this->employee->municipalSurchargeRatePercent;
            $regional = Dec::round(Dec::div(Dec::div(Dec::mul($previousYear, $regionalRate), '100'), '11'));
            $municipal = Dec::round(Dec::div(Dec::div(Dec::mul($previousYear, $municipalRate), '100'), '11'));
            $this->add(ItalianWageCodes::RegionalSurcharge, 'Addizionale regionale anno precedente (rata)', WageTypeKind::Deduction, $previousYear, $regionalRate, Dec::neg($regional));
            $this->add(ItalianWageCodes::MunicipalSurcharge, 'Addizionale comunale anno precedente (rata)', WageTypeKind::Deduction, $previousYear, $municipalRate, Dec::neg($municipal));
        }
    }

    public function employerCosts(): void
    {
        $parameters = $this->parameters;
        $rate = $this->context->agreement?->employerInpsRatePercent
            ?? $this->context->settings?->employerInpsRatePercent
            ?? Dec::mul($parameters->indicativeEmployerInpsRate, '100');
        $this->add(ItalianWageCodes::InpsEmployer, "Contributi INPS a carico dell'azienda", WageTypeKind::Employer, $this->inpsBasis, $rate, Dec::round(Dec::div(Dec::mul($this->inpsBasis, $rate), '100')));
        $tfrBasis = $this->grossTotal();
        $tfr = Dec::sub(Dec::div($tfrBasis, $parameters->tfrDivisor), Dec::mul($this->inpsBasis, $parameters->tfrSolidarityRate));
        $this->add(ItalianWageCodes::Tfr, 'Quota TFR maturata (al netto dello 0,50%)', WageTypeKind::Employer, $tfrBasis, '0', Dec::round($tfr));
    }

    public function build(): PayrollResult
    {
        $gross = $this->grossTotal();
        $deductions = Dec::neg($this->total(static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Deduction));
        $additions = $this->total(static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Expense);
        $employer = $this->total(static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Employer);
        $irpef = Dec::neg($this->total(static fn (PayslipLineData $line): bool => $line->wageTypeCode === ItalianWageCodes::Irpef));

        return new PayrollResult(new PayslipData(
            employeeId: $this->employee->id,
            year: $this->context->year,
            month: $this->context->month,
            employeeName: $this->employee->fullName,
            paidDays: $this->paidDays,
            grossSalary: $gross,
            ahvBasis: $this->inpsBasis,
            alvBasis: '0',
            uvgBasis: '0',
            ktgBasis: '0',
            bvgInsuredSalary: '0',
            withholdingTaxBasis: $this->taxable,
            withholdingTaxRatePercent: Dec::isPositive($this->taxable) ? Dec::round(Dec::mul(Dec::div($irpef, $this->taxable), '100')) : '0',
            withholdingTaxCode: null,
            withholdingTaxCanton: null,
            employeeDeductions: $deductions,
            netSalary: Dec::sub($gross, $deductions),
            payoutAmount: Dec::add(Dec::sub($gross, $deductions), $additions),
            employerContributions: $employer,
            warnings: $this->warnings === [] ? null : implode("\n", $this->warnings),
        ), $this->lines);
    }

    private static function workingDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $days = 0;

        for ($day = $from; $day <= $to; $day = PayrollDates::addDays($day, 1)) {
            if ($day->format('w') !== '0') {
                $days++;
            }
        }

        return $days;
    }

    private function installments(): int
    {
        return $this->context->agreement?->installments
            ?? ($this->employee->salaryInstallmentsPerYear > 0 ? $this->employee->salaryInstallmentsPerYear : 13);
    }

    private function isYearEnd(): bool
    {
        return $this->context->month === 12
            || ($this->employee->terminationDate !== null && $this->employee->terminationDate <= $this->last);
    }

    private function annualIrpef(string $income): string
    {
        $parameters = $this->parameters;

        return Dec::round(Dec::max('0', Dec::sub(
            Dec::sub($parameters->grossIrpef($income), $parameters->workDeduction($income)),
            $parameters->taxWedgeDeduction($income),
        )));
    }

    private function yearToDate(string $code): string
    {
        return Dec::sum(array_map(static fn (ItalianPreviousPayslip $payslip): string => $payslip->lineAmounts[$code] ?? '0', $this->context->yearToDate));
    }

    private function grossTotal(): string
    {
        return $this->total(static fn (PayslipLineData $line): bool => WageTypeKind::isGross($line->kind));
    }

    /**
     * @param  callable(PayslipLineData): bool  $filter
     */
    private function total(callable $filter): string
    {
        return Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, array_filter($this->lines, $filter)));
    }

    private function additionalInstallments(): void
    {
        $monthsInYear = $this->monthsEmployed(PayrollDates::monthStart($this->context->year, 1));
        $installmentRate = Dec::round(Dec::div($this->monthly, '12'));

        if ($this->installments() >= 13 && $this->isYearEnd()) {
            $amount = Dec::round(Dec::div(Dec::mul($this->monthly, (string) $monthsInYear), '12'));
            $this->add(ItalianWageCodes::Thirteenth, 'Tredicesima mensilità', WageTypeKind::Earning, (string) $monthsInYear, $installmentRate, $amount);
        }

        $fourteenthMonth = $this->context->agreement->fourteenthMonth ?? 7;

        if ($this->installments() >= 14 && $this->context->month === $fourteenthMonth) {
            $months = $this->monthsEmployed(PayrollDates::addMonths(PayrollDates::monthStart($this->context->year, $fourteenthMonth), -12));
            $this->add(ItalianWageCodes::Fourteenth, 'Quattordicesima mensilità', WageTypeKind::Earning, (string) $months, $installmentRate, Dec::round(Dec::div(Dec::mul($this->monthly, (string) $months), '12')));
        }
    }

    private function monthsEmployed(DateTimeImmutable $periodStart): int
    {
        $count = 0;
        $hire = $this->employee->hireDate;
        $end = $this->employee->terminationDate;

        for ($month = $periodStart; $month <= $this->first; $month = PayrollDates::addMonths($month, 1)) {
            $monthEnd = PayrollDates::addDays(PayrollDates::addMonths($month, 1), -1);
            $employed = $hire <= PayrollDates::addDays($monthEnd, -14)
                && ($end === null || $end >= PayrollDates::addDays($month, 14));

            if ($employed) {
                $count++;
            }
        }

        return min(12, $count);
    }

    private function add(string $code, string $description, string $kind, string $quantity, string $rate, string $amount): void
    {
        $this->lines[] = new PayslipLineData(
            wageTypeCode: $code,
            description: $description,
            kind: $kind,
            quantity: $quantity,
            rate: $rate,
            amount: Dec::round($amount),
            sortOrder: count($this->lines),
        );
    }
}
