<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\Payroll\PayrollRunServiceInterface;
use Modules\HumanResources\Contracts\Payroll\RateSetResolverInterface;
use Modules\HumanResources\Contracts\Payroll\SwissPayrollCalculatorInterface;
use Modules\HumanResources\Contracts\Payroll\WithholdingTaxServiceInterface;
use Modules\HumanResources\DTOs\Payroll\CreatePayrollRunDTO;
use Modules\HumanResources\DTOs\Payroll\EarningInput;
use Modules\HumanResources\DTOs\Payroll\PayslipCalculationInput;
use Modules\HumanResources\DTOs\Payroll\PayslipResult;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\DTOs\Payroll\UpdatePayrollRunDTO;
use Modules\HumanResources\DTOs\Payroll\WageTypeData;
use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;
use Modules\HumanResources\Events\PayrollRunApprovedEvent;
use Modules\HumanResources\Events\PayrollRunClosedEvent;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;
use Modules\HumanResources\Exceptions\Payroll\PayrollRunStateException;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\PayslipLine;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * Lifecycle of Swiss payroll runs: draft -> calculated -> approved -> closed. Every calculation is done in
 * memory first, so a failing employee (missing tariff, missing rate set...) leaves the run untouched.
 */
final readonly class PayrollRunService implements PayrollRunServiceInterface
{
    private const MODULE_CODE = 'MODULE_18_HR';

    public function __construct(
        private SwissPayrollCalculatorInterface $calculator,
        private RateSetResolverInterface $rateSetResolver,
        private WithholdingTaxServiceInterface $withholdingTaxService,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function createRun(CreatePayrollRunDTO $dto, ?string $actorUserId = null): PayrollRun
    {
        $periodStart = CarbonImmutable::parse(sprintf('%04d-%02d-01', $dto->periodYear, $dto->periodMonth))->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $this->assertKnownWageTypes($dto->inputs);

        return DB::transaction(function () use ($dto, $periodStart, $periodEnd): PayrollRun {
            $sequence = PayrollRun::withTrashed()
                ->where('tenant_id', $dto->tenantId)
                ->where('period_year', $dto->periodYear)
                ->where('period_month', $dto->periodMonth)
                ->count() + 1;

            $run = PayrollRun::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'run_number' => sprintf('PR-%04d%02d-%03d', $dto->periodYear, $dto->periodMonth, $sequence),
                'period_year' => $dto->periodYear,
                'period_month' => $dto->periodMonth,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'payment_date' => $dto->paymentDate,
                'status' => PayrollRunStatus::Draft,
                'currency' => 'CHF',
                'employee_ids' => $dto->employeeIds,
                'inputs' => $dto->inputs,
                'notes' => $dto->notes,
            ]);

            // Reload to expose the database defaults (totals, counters) to the caller.
            return $run->refresh();
        });
    }

    public function updateRun(PayrollRun $run, UpdatePayrollRunDTO $dto, ?string $actorUserId = null): PayrollRun
    {
        if (! $run->status->canBeEdited()) {
            throw PayrollRunStateException::cannot('edited', $run->status);
        }

        if ($dto->inputs !== null) {
            $this->assertKnownWageTypes($dto->inputs);
        }

        return DB::transaction(function () use ($run, $dto): PayrollRun {
            $this->lockAndAssert($run, 'edited', static fn (PayrollRunStatus $status): bool => $status->canBeEdited());
            $this->purgePayslips($run);

            $changes = array_filter([
                'payment_date' => $dto->paymentDate,
                'employee_ids' => $dto->employeeIds,
                'inputs' => $dto->inputs,
                'notes' => $dto->notes,
            ], static fn (mixed $value): bool => $value !== null);

            // Any change invalidates a previous calculation: the run goes back to draft.
            $run->update(array_merge($changes, $this->emptyTotals(), [
                'status' => PayrollRunStatus::Draft,
                'calculated_at' => null,
            ]));

            return $run->refresh();
        });
    }

    public function calculate(PayrollRun $run, ?string $actorUserId = null): PayrollRun
    {
        if (! $run->status->canBeCalculated()) {
            throw PayrollRunStateException::cannot('recalculated', $run->status);
        }

        $periodStart = CarbonImmutable::parse($run->period_start->toDateString())->startOfDay();
        $periodEnd = CarbonImmutable::parse($run->period_end->toDateString())->startOfDay();
        $rateSet = $this->rateSetResolver->resolve($periodStart, $run->periodCode());

        $baseSalaryCode = (string) config('payroll_ch.base_salary_wage_type', '1000');
        $inputs = $run->inputs ?? [];
        $this->assertKnownWageTypes($inputs);
        $wageTypes = $this->resolveWageTypes(array_merge([$baseSalaryCode], array_column($inputs, 'wage_type_code')));

        if (! isset($wageTypes[$baseSalaryCode])) {
            throw PayrollCalculationException::invalid(
                "The base salary wage type {$baseSalaryCode} is not defined.",
                ['base_salary_wage_type' => ["Wage type {$baseSalaryCode} is missing or inactive."]],
            );
        }

        $baseSalaryWageType = WageTypeData::fromModel($wageTypes[$baseSalaryCode]);

        $eligible = $this->eligibleEmployees($run, $periodStart, $periodEnd);
        $this->assertInputsTargetPaidEmployees($inputs, $eligible);

        /** @var list<array{profile: PayrollProfile, employee: Employee, contract: EmploymentContract, result: PayslipResult}> $calculated */
        $calculated = [];

        foreach ($eligible as $entry) {
            $input = $this->buildInput($entry, $periodStart, $periodEnd, $baseSalaryWageType, $wageTypes, $inputs);
            $result = $this->calculator->calculate($input, $rateSet);

            if ($result->daysWorked === 0) {
                continue;
            }

            $calculated[] = $entry + ['result' => $result];
        }

        return DB::transaction(function () use ($run, $rateSet, $calculated, $actorUserId): PayrollRun {
            $this->lockAndAssert($run, 'recalculated', static fn (PayrollRunStatus $status): bool => $status->canBeCalculated());
            $this->purgePayslips($run);
            $totals = $this->emptyTotals();

            foreach ($calculated as $entry) {
                $this->persistPayslip($run, $rateSet, $entry['profile'], $entry['employee'], $entry['contract'], $entry['result']);

                $result = $entry['result'];
                $totals['employee_count']++;
                $totals['gross_rappen'] += $result->grossRappen;
                $totals['employee_deductions_rappen'] += $result->employeeDeductionsRappen;
                $totals['employer_contributions_rappen'] += $result->employerContributionsRappen;
                $totals['withholding_tax_rappen'] += $result->withholdingTaxRappen;
                $totals['net_rappen'] += $result->netRappen;
            }

            $run->update(array_merge($totals, [
                'status' => PayrollRunStatus::Calculated,
                'rate_set_id' => $rateSet->id,
                'calculated_at' => now(),
            ]));

            $this->auditLedger->record(
                action: 'HR_PAYROLL_RUN_CALCULATED',
                model: $run,
                payloadBefore: null,
                payloadAfter: array_merge($totals, ['rate_set' => $rateSet->code, 'actor_user_id' => $actorUserId]),
                moduleCode: self::MODULE_CODE,
            );

            return $run->refresh();
        });
    }

    public function approve(PayrollRun $run, ?string $actorUserId = null): PayrollRun
    {
        if (! $run->status->canBeApproved()) {
            throw PayrollRunStateException::cannot('approved', $run->status);
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $this->lockAndAssert($run, 'approved', static fn (PayrollRunStatus $status): bool => $status->canBeApproved());

            $run->update([
                'status' => PayrollRunStatus::Approved,
                'approved_at' => now(),
                'approved_by_user_id' => $this->actorUuid($actorUserId),
            ]);

            $this->auditLedger->record(
                action: 'HR_PAYROLL_RUN_APPROVED',
                model: $run,
                payloadBefore: ['status' => PayrollRunStatus::Calculated->value],
                payloadAfter: ['status' => PayrollRunStatus::Approved->value],
                moduleCode: self::MODULE_CODE,
            );

            event(new PayrollRunApprovedEvent(...$this->eventPayload($run)));

            return $run->refresh();
        });
    }

    public function close(PayrollRun $run, ?string $actorUserId = null): PayrollRun
    {
        if (! $run->status->canBeClosed()) {
            throw PayrollRunStateException::cannot('closed', $run->status);
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $this->lockAndAssert($run, 'closed', static fn (PayrollRunStatus $status): bool => $status->canBeClosed());

            $run->update([
                'status' => PayrollRunStatus::Closed,
                'closed_at' => now(),
                'closed_by_user_id' => $this->actorUuid($actorUserId),
            ]);

            $this->auditLedger->record(
                action: 'HR_PAYROLL_RUN_CLOSED',
                model: $run,
                payloadBefore: ['status' => PayrollRunStatus::Approved->value],
                payloadAfter: ['status' => PayrollRunStatus::Closed->value],
                moduleCode: self::MODULE_CODE,
            );

            event(new PayrollRunClosedEvent(...$this->eventPayload($run)));

            return $run->refresh();
        });
    }

    /**
     * Employees with a payroll profile and an employment contract overlapping the period.
     *
     * @return list<array{profile: PayrollProfile, employee: Employee, contract: EmploymentContract}>
     */
    private function eligibleEmployees(PayrollRun $run, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $explicitEmployeeIds = $run->employee_ids;

        $profiles = PayrollProfile::query()
            ->with('employee')
            ->where('tenant_id', $run->tenant_id)
            ->when($explicitEmployeeIds !== null && $explicitEmployeeIds !== [], function (Builder $query) use ($explicitEmployeeIds): void {
                $query->whereIn('employee_id', $explicitEmployeeIds ?? []);
            })
            ->get();

        $eligible = [];
        $problems = [];

        foreach ($profiles as $profile) {
            $employee = $profile->employee;

            if ($employee === null) {
                continue;
            }

            $hireDate = $employee->hire_date;
            $terminationDate = $employee->termination_date;

            if ($hireDate->toDateString() > $periodEnd->toDateString()
                || ($terminationDate !== null && $terminationDate->toDateString() < $periodStart->toDateString())) {
                continue;
            }

            $contract = EmploymentContract::query()
                ->where('tenant_id', $run->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereDate('start_date', '<=', $periodEnd->toDateString())
                ->where(function (Builder $query) use ($periodStart): void {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart->toDateString());
                })
                ->orderByDesc('start_date')
                ->first();

            if ($contract === null) {
                if ($explicitEmployeeIds !== null && $explicitEmployeeIds !== []) {
                    $problems["employees.{$employee->id}.contract"] = ['No employment contract covers the payroll period.'];
                }

                continue;
            }

            $eligible[] = ['profile' => $profile, 'employee' => $employee, 'contract' => $contract];
        }

        if ($explicitEmployeeIds !== null && $explicitEmployeeIds !== []) {
            $withProfile = $profiles->pluck('employee_id')->map(static fn (mixed $id): string => (string) $id)->all();

            foreach (array_diff($explicitEmployeeIds, $withProfile) as $missingEmployeeId) {
                $problems["employees.{$missingEmployeeId}.profile"] = ['The employee has no Swiss payroll profile.'];
            }
        }

        if ($problems !== []) {
            throw PayrollCalculationException::invalid('Some selected employees cannot be paid in this run.', $problems);
        }

        return $eligible;
    }

    /**
     * @param  array{profile: PayrollProfile, employee: Employee, contract: EmploymentContract}  $entry
     * @param  array<string, WageType>  $wageTypes
     * @param  list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>  $inputs
     */
    private function buildInput(
        array $entry,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        WageTypeData $baseSalaryWageType,
        array $wageTypes,
        array $inputs,
    ): PayslipCalculationInput {
        $profile = $entry['profile'];
        $employee = $entry['employee'];
        $contract = $entry['contract'];
        $employeeId = (string) $employee->id;

        $employmentStart = max($employee->hire_date->toImmutable(), $contract->start_date->toImmutable());
        $endCandidates = array_filter([
            $employee->termination_date?->toImmutable(),
            $contract->end_date?->toImmutable(),
        ]);
        $employmentEnd = $endCandidates === [] ? null : min($endCandidates);

        $earnings = [];

        foreach ($inputs as $line) {
            if ((string) $line['employee_id'] !== $employeeId) {
                continue;
            }

            $earnings[] = new EarningInput(
                wageType: WageTypeData::fromModel($wageTypes[$line['wage_type_code']]),
                amountRappen: Rappen::fromDecimal((string) $line['amount']),
                label: $line['label'] ?? null,
            );
        }

        $withholdingTariff = null;

        if ($profile->isSubjectToWithholdingTax()) {
            $withholdingTariff = $this->withholdingTaxService->resolveTariff(
                canton: $profile->qst_canton ?? $profile->work_canton,
                tariffCode: (string) $profile->qst_tariff_code,
                churchTax: $profile->church_tax,
                date: $periodStart,
                employeeId: $employeeId,
            );
        }

        return new PayslipCalculationInput(
            employeeId: $employeeId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            employmentStart: $employmentStart,
            employmentEnd: $employmentEnd,
            birthDate: $employee->birth_date?->toImmutable(),
            annualSalaryRappen: Rappen::fromDecimal((string) $contract->base_annual_salary),
            // A percentage with two decimals parsed as "hundredths" is exactly basis points (60.00 -> 6000).
            fteBasisPoints: Rappen::fromDecimal((string) ($contract->fte_percentage ?? '100.00')),
            baseSalaryWageType: $baseSalaryWageType,
            workCanton: $profile->work_canton,
            earnings: $earnings,
            bvgInsured: $profile->bvg_insured,
            uvgClass: $profile->uvg_class,
            ktgInsured: $profile->ktg_insured,
            withholdingTariff: $withholdingTariff,
        );
    }

    private function persistPayslip(
        PayrollRun $run,
        RateSet $rateSet,
        PayrollProfile $profile,
        Employee $employee,
        EmploymentContract $contract,
        PayslipResult $result,
    ): void {
        $payslip = Payslip::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $run->tenant_id,
            'company_id' => $run->company_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payroll_profile_id' => $profile->id,
            'rate_set_id' => $rateSet->id,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'days_worked' => $result->daysWorked,
            'fte_percentage' => $contract->fte_percentage ?? '100.00',
            'gross_rappen' => $result->grossRappen,
            'ahv_base_rappen' => $result->ahvBaseRappen,
            'alv_base_rappen' => $result->alvBaseRappen,
            'alv_solidarity_base_rappen' => $result->alvSolidarityBaseRappen,
            'bvg_insured_salary_rappen' => $result->bvgInsuredSalaryRappen,
            'uvg_base_rappen' => $result->uvgBaseRappen,
            'qst_base_rappen' => $result->qstBaseRappen,
            'qst_tariff_code' => $result->qstTariffCode,
            'qst_canton' => $result->qstCanton,
            'withholding_tax_rappen' => $result->withholdingTaxRappen,
            'employee_deductions_rappen' => $result->employeeDeductionsRappen,
            'employer_contributions_rappen' => $result->employerContributionsRappen,
            'net_rappen' => $result->netRappen,
            'snapshot' => [
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'work_canton' => $profile->work_canton,
                'residence_canton' => $profile->residence_canton,
                'permit_type' => $profile->permit_type,
                'civil_status' => $profile->civil_status,
                'rate_set_code' => $rateSet->code,
                'rate_set_year' => $rateSet->year,
                'contract_number' => $contract->contract_number,
            ],
        ]);

        foreach ($result->lines as $index => $line) {
            PayslipLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $run->tenant_id,
                'payslip_id' => $payslip->id,
                'wage_type_id' => $line->wageTypeId,
                'code' => $line->code,
                'label' => $line->label,
                'line_type' => $line->lineType,
                'side' => $line->side,
                'base_rappen' => $line->baseRappen,
                'rate_ppm' => $line->ratePpm,
                'amount_rappen' => $line->amountRappen,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Re-reads the run with a row lock inside the transaction so two concurrent requests cannot both pass the
     * status check (e.g. a double approval emitting two events).
     *
     * @param  callable(PayrollRunStatus): bool  $isAllowed
     */
    private function lockAndAssert(PayrollRun $run, string $action, callable $isAllowed): void
    {
        $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

        if (! $isAllowed($locked->status)) {
            throw PayrollRunStateException::cannot($action, $locked->status);
        }
    }

    /**
     * @param  list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>  $inputs
     * @param  list<array{profile: PayrollProfile, employee: Employee, contract: EmploymentContract}>  $eligible
     */
    private function assertInputsTargetPaidEmployees(array $inputs, array $eligible): void
    {
        $paidEmployeeIds = array_map(static fn (array $entry): string => (string) $entry['employee']->id, $eligible);
        $problems = [];

        foreach ($inputs as $index => $line) {
            if (! in_array((string) $line['employee_id'], $paidEmployeeIds, true)) {
                $problems["inputs.{$index}.employee_id"] = ['The employee is not paid in this run (no payroll profile or no contract in the period).'];
            }
        }

        if ($problems !== []) {
            throw PayrollCalculationException::invalid('Some payroll inputs target employees that are not part of this run.', $problems);
        }
    }

    /**
     * Payslips are rebuilt on every calculation; the (run, employee) unique key requires a hard delete.
     */
    private function purgePayslips(PayrollRun $run): void
    {
        $payslipIds = Payslip::withTrashed()->where('payroll_run_id', $run->id)->pluck('id')->all();

        if ($payslipIds === []) {
            return;
        }

        PayslipLine::withTrashed()->whereIn('payslip_id', $payslipIds)->forceDelete();
        Payslip::withTrashed()->whereIn('id', $payslipIds)->forceDelete();
    }

    /**
     * @param  list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>  $inputs
     */
    private function assertKnownWageTypes(array $inputs): void
    {
        if ($inputs === []) {
            return;
        }

        $known = $this->resolveWageTypes(array_column($inputs, 'wage_type_code'));
        $problems = [];

        foreach ($inputs as $index => $line) {
            if (! isset($known[$line['wage_type_code']])) {
                $problems["inputs.{$index}.wage_type_code"] = ["Unknown or inactive wage type {$line['wage_type_code']}."];
            }
        }

        if ($problems !== []) {
            throw PayrollCalculationException::invalid('The payroll inputs reference unknown wage types.', $problems);
        }
    }

    /**
     * Active wage types by code; a tenant wage type overrides the system default with the same code.
     *
     * @param  array<int, string>  $codes
     * @return array<string, WageType>
     */
    private function resolveWageTypes(array $codes): array
    {
        $wageTypes = WageType::query()
            ->whereIn('code', array_values(array_unique($codes)))
            ->where('is_active', true)
            ->get();

        $byCode = [];

        foreach ($wageTypes as $wageType) {
            if (! isset($byCode[$wageType->code]) || $wageType->tenant_id !== null) {
                $byCode[$wageType->code] = $wageType;
            }
        }

        return $byCode;
    }

    /**
     * @return array{employee_count: int, gross_rappen: int, employee_deductions_rappen: int, employer_contributions_rappen: int, withholding_tax_rappen: int, net_rappen: int}
     */
    private function emptyTotals(): array
    {
        return [
            'employee_count' => 0,
            'gross_rappen' => 0,
            'employee_deductions_rappen' => 0,
            'employer_contributions_rappen' => 0,
            'withholding_tax_rappen' => 0,
            'net_rappen' => 0,
        ];
    }

    /**
     * @return array{payrollRunId: string, runNumber: string, periodCode: string, paymentDate: string|null, currency: string, employeeCount: int, grossRappen: int, employeeDeductionsRappen: int, employerContributionsRappen: int, withholdingTaxRappen: int, netRappen: int, tenantId: string, companyId: string}
     */
    private function eventPayload(PayrollRun $run): array
    {
        return [
            'payrollRunId' => (string) $run->id,
            'runNumber' => $run->run_number,
            'periodCode' => $run->periodCode(),
            'paymentDate' => $run->payment_date?->toDateString(),
            'currency' => $run->currency,
            'employeeCount' => $run->employee_count,
            'grossRappen' => $run->gross_rappen,
            'employeeDeductionsRappen' => $run->employee_deductions_rappen,
            'employerContributionsRappen' => $run->employer_contributions_rappen,
            'withholdingTaxRappen' => $run->withholding_tax_rappen,
            'netRappen' => $run->net_rappen,
            'tenantId' => (string) $run->tenant_id,
            'companyId' => (string) ($run->company_id ?? $run->tenant_id),
        ];
    }

    private function actorUuid(?string $actorUserId): ?string
    {
        return $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null;
    }
}
