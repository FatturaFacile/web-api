<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Modules\HumanResources\Models\Employee;
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
use Modules\Payroll\Calculation\Swiss\FamilyAllowanceRateData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollPreview;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollYearToDate;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;
use Modules\Payroll\Calculation\WageTypeData;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Models\SwissPayrollSettings;

/**
 * Loads the data of a month and runs the engines, without saving anything: the database side of the desktop
 * CalculatePayrollRunCommandHandler and ItalianPayrollRunBuilder.
 */
final class PayrollCalculator
{
    public function __construct(
        private readonly PayrollParameterResolver $parameters,
        private readonly EmployeeDataFactory $employees,
    ) {}

    /**
     * @return array<string, PayrollResult> the results by employee id
     */
    public function calculate(Tenant $tenant, int $year, int $month, ?string $employeeId = null): array
    {
        return match (strtoupper((string) $tenant->country)) {
            'CH' => $this->swiss($year, $month, $employeeId),
            'IT' => $this->italian($year, $month, $employeeId),
            default => throw new PayrollException("L'elaborazione mensile è disponibile per le aziende svizzere e italiane."),
        };
    }

    /**
     * The quick estimate of the country strategies of the desktop.
     */
    public function preview(Tenant $tenant, Employee $employee, int $year, int $month): PayrollPreview
    {
        $profile = PayrollEmployeeProfile::query()->where('employee_id', $employee->id)->first();
        $data = $this->employees->make($employee, $profile);
        $target = PayrollDates::monthStart($year, $month);

        return match (strtoupper((string) $tenant->country)) {
            'CH' => SwissPayrollPreview::calculate($data, $target, $this->parameters->swiss($year)[0]),
            'IT' => ItalianPayrollPreview::calculate($data, $target, $this->parameters->italian($year)[0]),
            default => throw new PayrollException("L'elaborazione mensile è disponibile per le aziende svizzere e italiane."),
        };
    }

    /**
     * @return array<string, PayrollResult>
     */
    private function swiss(int $year, int $month, ?string $employeeId): array
    {
        $settings = SwissPayrollSettings::query()->first()
            ?? throw new PayrollException('Impostazioni paghe mancanti: aprire Paghe > Impostazioni.');
        [$parameters, $warnings] = $this->parameters->swiss($year);
        $settingsData = self::swissSettings($settings);
        $wageTypes = $this->wageTypes();
        $employees = $this->employedIn($year, $month, $employeeId);
        $profiles = $this->profiles($employees);
        $inputs = $this->inputs($year, $month);
        $absences = $this->absences($year, $month);
        $children = PayrollEmployeeChild::query()->whereIn('employee_id', $employees->modelKeys())->get()->groupBy('employee_id');
        $rates = $this->familyAllowanceRates();
        $previous = Payslip::query()->where('year', $year)->where('month', '<', $month)->get()->groupBy('employee_id');
        $results = [];

        foreach ($employees as $employee) {
            $data = $this->employees->make($employee, $profiles->get($employee->id));
            $slips = $previous->get($employee->id, new Collection);
            $result = SwissPayrollEngine::calculate(new SwissPayrollContext(
                employee: $data,
                year: $year,
                month: $month,
                wageTypes: $wageTypes,
                settings: $settingsData,
                inputs: $inputs->get($employee->id, []),
                absences: $absences->get($employee->id, []),
                children: self::childrenData($children->get($employee->id, new Collection)),
                familyAllowanceRates: $rates,
                withholdingTaxTariffs: $this->tariffs($data, $settingsData),
                yearToDate: new SwissPayrollYearToDate(
                    Dec::sum($slips->map(static fn (Payslip $slip): string => (string) $slip->paid_days)),
                    Dec::sum($slips->map(static fn (Payslip $slip): string => (string) $slip->alv_basis)),
                    Dec::sum($slips->map(static fn (Payslip $slip): string => (string) $slip->uvg_basis)),
                ),
                parameters: $parameters,
                parameterWarnings: $warnings,
            ));

            if ($result !== null) {
                $results[$employee->id] = $result;
            }
        }

        return $results;
    }

    /**
     * @return array<string, PayrollResult>
     */
    private function italian(int $year, int $month, ?string $employeeId): array
    {
        [$parameters, $warnings] = $this->parameters->italian($year);
        $settings = ItalianPayrollSettings::query()->first();
        $settingsData = $settings === null ? null : new ItalianPayrollSettingsData((string) $settings->employee_inps_rate_percent, (string) $settings->employer_inps_rate_percent);
        $employees = $this->employedIn($year, $month, $employeeId);
        $profiles = $this->profiles($employees);
        $inputs = $this->inputs($year, $month);
        $absences = $this->absences($year, $month);
        $agreements = CollectiveAgreement::query()->get()->sortBy(static fn (CollectiveAgreement $agreement): int => $agreement->isGeneric() ? 1 : 0);
        $levels = CollectiveAgreementLevel::query()->get();
        $previous = Payslip::query()->where('year', $year)->where('month', '<', $month)->get();
        $previousLines = PayslipLine::query()->whereIn('payslip_id', $previous->modelKeys())->get()->groupBy('payslip_id');
        $previousByEmployee = $previous->groupBy('employee_id');
        $lastYear = Payslip::query()->where('year', $year - 1)->get()->groupBy('employee_id');
        $results = [];

        foreach ($employees as $employee) {
            $profile = $profiles->get($employee->id);
            $agreement = $profile?->collective_agreement_code === null
                ? null
                : $agreements->first(static fn (CollectiveAgreement $candidate): bool => strcasecmp($candidate->code, (string) $profile->collective_agreement_code) === 0);
            $level = $agreement === null
                ? null
                : $levels->first(static fn (CollectiveAgreementLevel $candidate): bool => $candidate->agreement_id === $agreement->id && strcasecmp($candidate->level, (string) $profile->contract_level) === 0);

            $result = ItalianPayrollEngine::calculate(new ItalianPayrollContext(
                employee: $this->employees->make($employee, $profile),
                year: $year,
                month: $month,
                settings: $settingsData,
                agreement: $agreement === null ? null : new CollectiveAgreementData($agreement->installments, $agreement->fourteenth_month, $agreement->employer_inps_rate_percent),
                level: $level === null ? null : new CollectiveAgreementLevelData($level->level, (string) $level->monthly_minimum),
                inputs: $inputs->get($employee->id, []),
                absences: $absences->get($employee->id, []),
                yearToDate: self::previousPayslips($previousByEmployee->get($employee->id, new Collection), $previousLines),
                previousYearTaxable: Dec::sum($lastYear->get($employee->id, new Collection)->map(static fn (Payslip $slip): string => (string) $slip->withholding_tax_basis)),
                parameters: $parameters,
                parameterWarnings: $warnings,
            ));

            if ($result !== null) {
                $results[$employee->id] = $result;
            }
        }

        return $results;
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employedIn(int $year, int $month, ?string $employeeId): Collection
    {
        return Employee::query()
            ->when($employeeId !== null, static fn ($query) => $query->whereKey($employeeId))
            ->whereDate('hire_date', '<=', PayrollDates::monthEnd($year, $month)->format('Y-m-d'))
            ->where(static fn ($query) => $query->whereNull('termination_date')->orWhereDate('termination_date', '>=', PayrollDates::monthStart($year, $month)->format('Y-m-d')))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return \Illuminate\Support\Collection<string, PayrollEmployeeProfile>
     */
    private function profiles(Collection $employees): \Illuminate\Support\Collection
    {
        return PayrollEmployeeProfile::query()->whereIn('employee_id', $employees->modelKeys())->get()->keyBy('employee_id')->toBase();
    }

    /**
     * @return array<string, WageTypeData>
     */
    private function wageTypes(): array
    {
        $types = [];

        foreach (PayrollWageType::query()->get()->sortBy(static fn (PayrollWageType $type): int => $type->isGeneric() ? 0 : 1) as $type) {
            $types[$type->code] = new WageTypeData(
                code: $type->code,
                name: $type->name,
                kind: $type->kind,
                subjectToAhv: $type->subject_to_ahv,
                subjectToAlv: $type->subject_to_alv,
                subjectToUvg: $type->subject_to_uvg,
                subjectToKtg: $type->subject_to_ktg,
                subjectToBvg: $type->subject_to_bvg,
                subjectToWithholdingTax: $type->subject_to_withholding_tax,
                isIrregular: $type->is_irregular,
                certificateBox: $type->certificate_box,
                sortOrder: $type->sort_order,
                isSystem: $type->is_system,
            );
        }

        return $types;
    }

    /**
     * @return \Illuminate\Support\Collection<string, list<PayrollInputData>>
     */
    private function inputs(int $year, int $month): \Illuminate\Support\Collection
    {
        return PayrollInput::query()
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id')
            ->map(static fn (Collection $inputs): array => $inputs->map(static fn (PayrollInput $input): PayrollInputData => new PayrollInputData(
                $input->wage_type_code,
                (string) $input->quantity,
                (string) $input->rate,
                (string) $input->amount,
                $input->note,
            ))->all())
            ->toBase();
    }

    /**
     * @return \Illuminate\Support\Collection<string, list<AbsenceData>>
     */
    private function absences(int $year, int $month): \Illuminate\Support\Collection
    {
        return PayrollAbsence::query()
            ->whereDate('start_date', '<=', PayrollDates::monthEnd($year, $month)->format('Y-m-d'))
            ->whereDate('end_date', '>=', PayrollDates::monthStart($year, $month)->format('Y-m-d'))
            ->orderBy('start_date')
            ->get()
            ->groupBy('employee_id')
            ->map(static fn (Collection $absences): array => $absences->map(static fn (PayrollAbsence $absence): AbsenceData => new AbsenceData(
                $absence->absence_type,
                PayrollDates::from($absence->start_date),
                PayrollDates::from($absence->end_date),
                (string) $absence->percent,
            ))->all())
            ->toBase();
    }

    /**
     * Company rows replace the generic rows of the same canton and date.
     *
     * @return list<FamilyAllowanceRateData>
     */
    private function familyAllowanceRates(): array
    {
        $rows = PayrollFamilyAllowanceRate::query()->get();
        $own = $rows->reject(static fn (PayrollFamilyAllowanceRate $rate): bool => $rate->isGeneric())
            ->map(static fn (PayrollFamilyAllowanceRate $rate): string => $rate->canton.$rate->valid_from->format('Y-m-d'))
            ->all();

        return $rows
            ->reject(static fn (PayrollFamilyAllowanceRate $rate): bool => $rate->isGeneric() && in_array($rate->canton.$rate->valid_from->format('Y-m-d'), $own, true))
            ->map(static fn (PayrollFamilyAllowanceRate $rate): FamilyAllowanceRateData => new FamilyAllowanceRateData(
                $rate->canton,
                PayrollDates::from($rate->valid_from),
                (string) $rate->child_allowance,
                (string) $rate->education_allowance,
            ))
            ->values()
            ->all();
    }

    /**
     * The rows of the canton and code of an employee. A tariff imported by the company replaces the generic one of the
     * same date.
     *
     * @return list<WithholdingTaxTariffData>
     */
    private function tariffs(EmployeeData $employee, SwissPayrollSettingsData $settings): array
    {
        if (! $employee->isWithholdingTaxSubject || trim((string) $employee->withholdingTaxCode) === '') {
            return [];
        }

        $resident = strtoupper($employee->residenceCountry ?? 'CH') === 'CH';
        $canton = ($resident ? $employee->residenceCanton ?? $employee->workCanton : $employee->workCanton) ?? $settings->defaultWorkCanton;
        $rows = PayrollWithholdingTaxTariff::query()
            ->where('canton', strtoupper(trim($canton)))
            ->where('tariff_code', strtoupper(trim((string) $employee->withholdingTaxCode)))
            ->orderBy('valid_from')
            ->orderBy('income_from')
            ->get();
        $ownDates = $rows->reject(static fn (PayrollWithholdingTaxTariff $row): bool => $row->isGeneric())
            ->map(static fn (PayrollWithholdingTaxTariff $row): string => $row->valid_from->format('Y-m-d'))
            ->unique()
            ->all();

        return $rows
            ->reject(static fn (PayrollWithholdingTaxTariff $row): bool => $row->isGeneric() && in_array($row->valid_from->format('Y-m-d'), $ownDates, true))
            ->map(static fn (PayrollWithholdingTaxTariff $row): WithholdingTaxTariffData => new WithholdingTaxTariffData(
                $row->canton,
                $row->tariff_code,
                PayrollDates::from($row->valid_from),
                (string) $row->income_from,
                (string) $row->income_step,
                (string) $row->minimum_tax,
                (string) $row->rate_percent,
            ))
            ->values()
            ->all();
    }

    private static function swissSettings(SwissPayrollSettings $settings): SwissPayrollSettingsData
    {
        return new SwissPayrollSettingsData(
            ahvEmployeeRatePercent: (string) $settings->ahv_employee_rate_percent,
            ahvEmployerRatePercent: (string) $settings->ahv_employer_rate_percent,
            ahvAdministrationCostPercent: (string) $settings->ahv_administration_cost_percent,
            alvEmployeeRatePercent: (string) $settings->alv_employee_rate_percent,
            alvEmployerRatePercent: (string) $settings->alv_employer_rate_percent,
            alvAnnualCeiling: (string) $settings->alv_annual_ceiling,
            uvgAnnualCeiling: (string) $settings->uvg_annual_ceiling,
            nbuEmployeeRatePercent: (string) $settings->nbu_employee_rate_percent,
            buEmployerRatePercent: (string) $settings->bu_employer_rate_percent,
            ktgEmployeeRatePercent: (string) $settings->ktg_employee_rate_percent,
            ktgEmployerRatePercent: (string) $settings->ktg_employer_rate_percent,
            fakEmployerRatePercent: (string) $settings->fak_employer_rate_percent,
            bvgEmployerSharePercent: (string) $settings->bvg_employer_share_percent,
            bvgRiskRatePercent: (string) $settings->bvg_risk_rate_percent,
            pensionerAllowanceMonthly: (string) $settings->pensioner_allowance_monthly,
            defaultWorkCanton: $settings->default_work_canton,
        );
    }

    /**
     * @param  Collection<int, PayrollEmployeeChild>  $children
     * @return list<ChildData>
     */
    private static function childrenData(Collection $children): array
    {
        return $children->map(static fn (PayrollEmployeeChild $child): ChildData => new ChildData(
            PayrollDates::from($child->date_of_birth),
            $child->in_education,
            $child->education_end_date === null ? null : PayrollDates::from($child->education_end_date),
            $child->allowance_eligible,
        ))->values()->all();
    }

    /**
     * @param  Collection<int, Payslip>  $slips
     * @param  \Illuminate\Support\Collection<string, Collection<int, PayslipLine>>  $lines
     * @return list<ItalianPreviousPayslip>
     */
    private static function previousPayslips(Collection $slips, \Illuminate\Support\Collection $lines): array
    {
        return $slips->map(static function (Payslip $slip) use ($lines): ItalianPreviousPayslip {
            $amounts = [];

            foreach ($lines->get($slip->id, []) as $line) {
                $amounts[$line->wage_type_code] = Dec::add($amounts[$line->wage_type_code] ?? '0', (string) $line->amount);
            }

            return new ItalianPreviousPayslip((string) $slip->ahv_basis, (string) $slip->withholding_tax_basis, $amounts);
        })->values()->all();
    }
}
