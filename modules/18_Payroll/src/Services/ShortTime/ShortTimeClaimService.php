<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\ShortTime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\Swiss\ShortTimeCompensation;
use Modules\Payroll\Calculation\Swiss\SwissPayrollRules;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\WageTypeData;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Exports\CsvBuilder;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\ShortTimeCase;
use Modules\Payroll\Models\ShortTimeClaim;
use Modules\Payroll\Models\ShortTimeLine;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\LegalParameterResolver;
use Modules\Payroll\Services\LegalParameterSet;

/**
 * Builds the monthly claim of a case from the working time or from entered hours, carries it into the payroll inputs and
 * follows it to the reimbursement. Bad-weather and short-time periods share the framework period and its count.
 */
final class ShortTimeClaimService
{
    public function __construct(
        private readonly LegalParameterResolver $parameters,
        private readonly WorkingTimeCalculator $calculator,
    ) {}

    /**
     * @param  list<array{employee_id: string, target_hours?: string|int|float|null, worked_hours?: string|int|float|null, absence_hours?: string|int|float|null, overtime_hours?: string|int|float|null, regular_allowances?: string|int|float|null, consented?: bool}>  $entries
     */
    public function calculate(ShortTimeCase $case, int $year, int $month, array $entries): ShortTimeClaim
    {
        $set = $this->parameters->resolve($case->parameterTopic(), $year);
        self::assertRecalculable(ShortTimeClaim::query()->where('short_time_case_id', $case->id)->where('year', $year)->where('month', $month)->first());

        $employees = Employee::query()->whereIn('id', array_column($entries, 'employee_id'))->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $employees->keys())->get()->keyBy('employee_id');
        $lines = [];

        foreach ($entries as $entry) {
            $employee = $employees->get($entry['employee_id']);

            if ($employee !== null) {
                $lines[] = $this->line($case, $employee, $profiles->get($employee->id), $entry, $year, $month, $set);
            }
        }

        return DB::transaction(function () use ($case, $year, $month, $lines, $set): ShortTimeClaim {
            $existing = ShortTimeClaim::query()->where('short_time_case_id', $case->id)->where('year', $year)->where('month', $month)->lockForUpdate()->first();
            self::assertRecalculable($existing);

            return $this->store($case, $existing, $year, $month, $lines, $set);
        });
    }

    /**
     * Only a draft that never reached the payroll is calculated again.
     */
    private static function assertRecalculable(?ShortTimeClaim $existing): void
    {
        if ($existing === null) {
            return;
        }

        if ($existing->applied_at !== null) {
            throw new PayrollException('La richiesta è già stata riportata nelle paghe: non si ricalcola.');
        }

        if ($existing->status !== ShortTimeClaim::StatusDraft) {
            throw new PayrollException('La richiesta è già stata inoltrata: non si ricalcola.');
        }
    }

    /**
     * The claim goes to the unemployment fund: from now on it does not change.
     */
    public function submit(ShortTimeClaim $claim): ShortTimeClaim
    {
        if ($claim->status !== ShortTimeClaim::StatusDraft) {
            throw new PayrollException('La richiesta è già stata inoltrata.');
        }

        if (! $claim->meets_minimum_loss) {
            throw new PayrollException('La perdita di lavoro non raggiunge il minimo: nessuna indennità è dovuta per il periodo.');
        }

        $claim->update(['status' => ShortTimeClaim::StatusSubmitted, 'submitted_at' => CarbonImmutable::now()]);

        return $claim;
    }

    public function recordReimbursement(ShortTimeClaim $claim, string $amount, ?string $paidOn): ShortTimeClaim
    {
        if ($claim->status !== ShortTimeClaim::StatusSubmitted) {
            throw new PayrollException('Si registra il rimborso di una richiesta inoltrata.');
        }

        $claim->update([
            'status' => ShortTimeClaim::StatusReimbursed,
            'reimbursed_amount' => $amount,
            'reimbursed_at' => $paidOn === null ? CarbonImmutable::now() : CarbonImmutable::parse($paidOn),
        ]);

        return $claim;
    }

    /**
     * Carries the claim into the inputs of its month: the wage goes down by the compensable hours and the compensation
     * comes in. The social contributions stay on the full salary because the reduction is outside their bases, as the law
     * asks during short-time work; the tax follows what is really paid.
     */
    public function applyToPayroll(ShortTimeClaim $claim): ShortTimeClaim
    {
        DB::transaction(function () use ($claim): void {
            $claim = ShortTimeClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->applied_at !== null) {
                throw new PayrollException('La richiesta è già stata riportata nelle paghe.');
            }

            if (! in_array($claim->status, [ShortTimeClaim::StatusSubmitted, ShortTimeClaim::StatusReimbursed], true)) {
                throw new PayrollException('Si riporta nelle paghe una richiesta inoltrata alla cassa.');
            }

            if (! $claim->meets_minimum_loss) {
                throw new PayrollException('La perdita di lavoro non raggiunge il minimo: nessuna indennità è dovuta per il periodo.');
            }

            $claim->load('lines', 'shortTimeCase');
            $isBadWeather = $claim->shortTimeCase->kind === ShortTimeCase::BadWeather;
            $compensationCode = $isBadWeather ? SwissWageTypeCatalog::BadWeatherCompensation : SwissWageTypeCatalog::ShortTimeCompensation;

            $this->ensureWageTypes();

            foreach ($claim->lines->where('is_eligible', true) as $line) {
                if (! Dec::isPositive((string) $line->compensation)) {
                    continue;
                }

                PayrollInput::query()->create(['employee_id' => $line->employee_id, 'year' => $claim->year, 'month' => $claim->month, 'wage_type_code' => SwissWageTypeCatalog::ShortTimeReduction, 'quantity' => 0, 'rate' => 0, 'amount' => Dec::neg((string) $line->wage_reduction), 'note' => "Ore non lavorate {$line->compensable_hours}"]);
                PayrollInput::query()->create(['employee_id' => $line->employee_id, 'year' => $claim->year, 'month' => $claim->month, 'wage_type_code' => $compensationCode, 'quantity' => 0, 'rate' => 0, 'amount' => (string) $line->compensation, 'note' => "Richiesta {$claim->year}-{$claim->month}"]);
            }

            $claim->update(['applied_at' => CarbonImmutable::now()]);
        });

        return $claim->refresh();
    }

    /**
     * The figures for the forms of the fund. The forms themselves stay those of SECO (1042 series) or its eServices.
     */
    public function file(ShortTimeClaim $claim): GeneratedFile
    {
        $claim->loadMissing('lines.employee', 'shortTimeCase');
        $csv = new CsvBuilder;
        $csv->row('Dipendente', 'Ore contrattuali', 'Ore lavorate', 'Assenze', 'Ore in più dedotte', 'Ore perse', 'Attesa', 'Ore indennizzabili', 'Salario assicurato', 'Perdita di guadagno', 'Indennità', 'Rimborso contributi', 'Avente diritto', 'Motivo');

        foreach ($claim->lines as $line) {
            $csv->row($line->employee?->full_name, $line->target_hours, $line->worked_hours, $line->absence_hours, $line->overtime_deducted_hours, $line->lost_hours, $line->waiting_hours, $line->compensable_hours, $line->insured_monthly_salary, $line->lost_earnings, $line->compensation, $line->employer_refund, $line->is_eligible, $line->ineligibility_reason);
        }

        return new GeneratedFile(
            sprintf('Richiesta_%s_%d-%02d.csv', $claim->shortTimeCase->kind, $claim->year, $claim->month),
            $csv->encoded(),
            'Dati per i moduli della cassa di disoccupazione o per i suoi eServices. Da presentare entro il '.$claim->claim_due_on->format('d.m.Y').'. Non è il modulo ufficiale.',
        );
    }

    /**
     * @param  array{employee_id: string, target_hours?: string|int|float|null, worked_hours?: string|int|float|null, absence_hours?: string|int|float|null, overtime_hours?: string|int|float|null, regular_allowances?: string|int|float|null, consented?: bool}  $entry
     * @return array<string, mixed>
     */
    private function line(ShortTimeCase $case, Employee $employee, ?PayrollEmployeeProfile $profile, array $entry, int $year, int $month, LegalParameterSet $set): array
    {
        [$target, $worked, $absence, $hoursKnown] = $this->hours($employee, $entry, $year, $month);
        $reason = $hoursKnown ? $this->ineligibility($employee, $profile, $entry, $year, $month) : "Ore non determinabili: serve il controllo dell'orario di lavoro.";
        $overtime = Dec::of((string) ($entry['overtime_hours'] ?? '0'));
        $weeklyHours = (string) ($profile->weekly_hours ?? '42');
        $monthly = Dec::add($this->monthlySalary($employee, $profile, $target), Dec::of((string) ($entry['regular_allowances'] ?? '0')));

        $result = ShortTimeCompensation::calculate(
            $target,
            $worked,
            $absence,
            $overtime,
            $monthly,
            $weeklyHours,
            (string) $set->required('waiting_days_per_period'),
            (string) $set->required('compensation_percent'),
            (string) $set->required('maximum_insured_monthly_salary'),
        );

        return [
            'employee_id' => $employee->id,
            'is_eligible' => $reason === null,
            'ineligibility_reason' => $reason,
            'target_hours' => Dec::round($target),
            'worked_hours' => Dec::round($worked),
            'absence_hours' => Dec::round($absence),
            'overtime_deducted_hours' => Dec::round($overtime),
            ...($reason === null ? $result : ['lost_hours' => $result['lost_hours'], 'insured_monthly_salary' => $result['insured_monthly_salary']]),
        ];
    }

    /**
     * Entered hours win; without them the hours come from the working time of the employee.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: string, 2: string, 3: bool}
     */
    private function hours(Employee $employee, array $entry, int $year, int $month): array
    {
        if (isset($entry['target_hours'], $entry['worked_hours'])) {
            return [Dec::of((string) $entry['target_hours']), Dec::of((string) $entry['worked_hours']), Dec::of((string) ($entry['absence_hours'] ?? '0')), true];
        }

        $user = $employee->user;

        if ($user === null) {
            return ['0', '0', '0', false];
        }

        $first = PayrollDates::monthStart($year, $month)->format('Y-m-d');
        $last = PayrollDates::monthEnd($year, $month)->format('Y-m-d');
        $report = $this->calculator->evaluate($user, $first, $last);
        $target = array_sum(array_map(static fn ($day): int => $day->targetMinutes, $report->days));
        $worked = array_sum(array_map(static fn ($day): int => $day->workedMinutes, $report->days));
        $credited = array_sum(array_map(static fn ($day): int => $day->creditedMinutes, $report->days));

        return [Dec::div((string) $target, '60'), Dec::div((string) $worked, '60'), Dec::div((string) $credited, '60'), true];
    }

    /**
     * Who has no right to the compensation (SECO 716.400, questions 4 and 6). Controlling persons and their spouses are
     * facts the application does not hold: the employer leaves them out of the entries.
     *
     * @param  array<string, mixed>  $entry
     */
    private function ineligibility(Employee $employee, ?PayrollEmployeeProfile $profile, array $entry, int $year, int $month): ?string
    {
        $periodEnd = PayrollDates::monthEnd($year, $month);
        $contractType = $employee->activeContract?->contract_type;

        return match (true) {
            ($entry['consented'] ?? true) === false => 'Dipendente non consenziente: va retribuito secondo contratto.',
            $employee->termination_date !== null => 'Rapporto di lavoro disdetto.',
            $contractType === 'apprenticeship' || $employee->employment_type === 'apprentice' => 'Apprendista.',
            $contractType === 'contractor' || $employee->employment_type === 'contractor' => 'Non è un dipendente della ditta.',
            ($profile?->is_fixed_term_contract ?? false) || $contractType === 'fixed_term' => 'Contratto a tempo determinato.',
            $employee->birth_date !== null && SwissPayrollRules::referenceAgeDate(PayrollDates::from($employee->birth_date), $profile?->gender) <= $periodEnd => 'Età di riferimento AVS raggiunta.',
            default => null,
        };
    }

    private function monthlySalary(Employee $employee, ?PayrollEmployeeProfile $profile, string $targetHours): string
    {
        if (($profile->pay_type ?? 'monthly') === 'hourly') {
            return Dec::mul((string) $profile->hourly_wage, $targetHours);
        }

        return Dec::div(Dec::mul((string) $employee->base_annual_salary, (string) $employee->fte_ratio), (string) ($profile->salary_installments_per_year ?? 12));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function store(ShortTimeCase $case, ?ShortTimeClaim $claim, int $year, int $month, array $lines, LegalParameterSet $set): ShortTimeClaim
    {
        $eligible = collect($lines)->where('is_eligible', true);
        $target = Dec::sum($eligible->map(static fn (array $line): string => Dec::sub($line['target_hours'], $line['absence_hours'])));
        $lost = Dec::sum($eligible->map(static fn (array $line): string => $line['lost_hours']));
        $lossPercent = Dec::isPositive($target) ? Dec::div(Dec::mul($lost, '100'), $target) : '0';
        $minimum = $set->optional('minimum_loss_percent');
        $meetsMinimum = $minimum === null || Dec::cmp($lossPercent, (string) $minimum) >= 0;
        $periodEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        $refundBasis = $set->optional('employer_refund_basis');
        $rate = $this->employerRate();
        $warnings = [...$set->warnings, ...$this->warnings($case, $year, $month, $lossPercent, $set)];

        if ($refundBasis === null) {
            $warnings[] = 'Rimborso dei contributi del datore di lavoro non calcolato: la base (employer_refund_basis) non è verificata.';
        }

        $claim ??= new ShortTimeClaim(['short_time_case_id' => $case->id, 'year' => $year, 'month' => $month]);
        $claim->fill([
            'parameters_year' => $set->year,
            'target_hours' => Dec::round($target),
            'lost_hours' => Dec::round($lost),
            'loss_percent' => Dec::round($lossPercent),
            'meets_minimum_loss' => $meetsMinimum,
            'total_compensation' => '0',
            'total_employer_refund' => '0',
            'claim_due_on' => $periodEnd->addMonthsNoOverflow((int) $set->required('claim_deadline_months'))->format('Y-m-d'),
            'warnings' => $meetsMinimum ? $warnings : [...$warnings, 'Perdita di lavoro del '.Dec::round($lossPercent)." % sotto il minimo del {$minimum} %: nessuna indennità."],
        ])->save();

        ShortTimeLine::query()->where('short_time_claim_id', $claim->id)->delete();
        $totalCompensation = '0';
        $totalRefund = '0';

        foreach ($lines as $line) {
            if (! $meetsMinimum) {
                unset($line['compensation'], $line['wage_reduction'], $line['lost_earnings'], $line['compensable_hours'], $line['waiting_hours']);
            }

            $refund = $refundBasis === null || ! isset($line['compensation']) ? '0' : Dec::round(Dec::div(Dec::mul($refundBasis === 'compensation' ? $line['compensation'] : $line['lost_earnings'], $rate), '100'));
            ShortTimeLine::query()->create([...$line, 'short_time_claim_id' => $claim->id, 'employer_refund' => $refund]);
            $totalCompensation = Dec::add($totalCompensation, (string) ($line['compensation'] ?? '0'));
            $totalRefund = Dec::add($totalRefund, $refund);
        }

        $claim->update(['total_compensation' => Dec::round($totalCompensation), 'total_employer_refund' => Dec::round($totalRefund)]);

        return $claim->load('lines');
    }

    /**
     * @return list<string>
     */
    private function warnings(ShortTimeCase $case, int $year, int $month, string $lossPercent, LegalParameterSet $set): array
    {
        $warnings = [];

        if ($case->kind === ShortTimeCase::ShortTime && $case->notified_on !== null) {
            $days = (int) $set->required($case->is_urgent ? 'urgent_notice_days' : 'notice_days');

            if ($case->notified_on->copy()->addDays($days)->gt($case->starts_on)) {
                $warnings[] = "Preannuncio tardivo: la perdita conta solo dopo il termine di {$days} giorni.";
            }
        }

        $periods = $this->periodsInFramework($case, $year, $month);
        $maximum = (int) $set->required('maximum_periods');

        if ($periods > $maximum) {
            $warnings[] = "Periodi di conteggio {$periods} nel termine quadro, oltre il massimo di {$maximum}.";
        }

        $highLoss = $set->optional('high_loss_percent');

        if ($highLoss !== null && Dec::cmp($lossPercent, (string) $highLoss) > 0) {
            $warnings[] = "Perdita oltre il {$highLoss} %: computabile al massimo per {$set->required('high_loss_maximum_periods')} periodi nel termine quadro.";
        }

        return $warnings;
    }

    /**
     * The months of short-time work and bad weather of the framework period, this one included. Claims without
     * compensation do not count (SECO 716.400, question 22).
     */
    private function periodsInFramework(ShortTimeCase $case, int $year, int $month): int
    {
        $current = CarbonImmutable::create($year, $month, 1);
        $earliest = $current->subMonths(23);
        $claims = ShortTimeClaim::query()
            ->with('shortTimeCase')
            ->where('total_compensation', '>', 0)
            ->get()
            ->filter(static function (ShortTimeClaim $claim) use ($case, $earliest, $current): bool {
                $date = CarbonImmutable::create($claim->year, $claim->month, 1);

                return $date >= $earliest && $date < $current && $claim->shortTimeCase?->department_id === $case->department_id;
            });

        return $claims->map(static fn (ShortTimeClaim $claim): string => "{$claim->year}-{$claim->month}")->unique()->count() + 1;
    }

    private function employerRate(): string
    {
        $settings = SwissPayrollSettings::query()->first();

        return Dec::add((string) ($settings->ahv_employer_rate_percent ?? '0'), (string) ($settings->alv_employer_rate_percent ?? '0'));
    }

    /**
     * Companies set up before this feature get the wage types when they first need them.
     */
    private function ensureWageTypes(): void
    {
        $existing = PayrollWageType::query()->whereNotNull('tenant_id')->pluck('code')->all();

        /** @var Collection<int, WageTypeData> $types */
        $types = collect(SwissWageTypeCatalog::shortTimeTypes());

        foreach ($types as $type) {
            if (in_array($type->code, $existing, true)) {
                continue;
            }

            PayrollWageType::query()->create([
                'code' => $type->code,
                'name' => $type->name,
                'kind' => $type->kind,
                'subject_to_ahv' => $type->subjectToAhv,
                'subject_to_alv' => $type->subjectToAlv,
                'subject_to_uvg' => $type->subjectToUvg,
                'subject_to_ktg' => $type->subjectToKtg,
                'subject_to_bvg' => $type->subjectToBvg,
                'subject_to_withholding_tax' => $type->subjectToWithholdingTax,
                'is_irregular' => $type->isIrregular,
                'certificate_box' => $type->certificateBox,
                'sort_order' => $type->sortOrder,
                'is_system' => $type->isSystem,
            ]);
        }
    }
}
