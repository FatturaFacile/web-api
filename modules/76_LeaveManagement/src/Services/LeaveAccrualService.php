<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Enums\DeadlineAlertLevel;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\Events\LeaveAccrualLedgerUpdatedEvent;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;

/**
 * Calculates monthly pro-rata leave accruals across CH (Art. 329a CO) and IT (Ferie, ROL, Ex-Festività),
 * factoring FTE percentage, hire/termination dates, carry-over caps, and IT 18-month INPS contribution deadlines.
 */
final readonly class LeaveAccrualService
{
    public function __construct(
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Run idempotent monthly accrual calculation for a tenant (and optional single employee).
     *
     * @return list<EmployeeLeaveAccrualLedger>
     */
    public function calculateMonthlyAccruals(
        string $tenantId,
        int $fiscalYear,
        int $month,
        ?string $employeeId = null,
        ?string $countryFilter = null,
    ): array {
        $periodKey = sprintf('%04d-%02d', $fiscalYear, $month);
        $monthStart = CarbonImmutable::create($fiscalYear, $month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($tenantId, $fiscalYear, $periodKey, $monthStart, $monthEnd, $employeeId, $countryFilter): array {
            $employees = Employee::query()
                ->where('tenant_id', $tenantId)
                ->with('activeContract')
                ->when($employeeId !== null, static fn ($q) => $q->where('id', $employeeId))
                ->whereDate('hire_date', '<=', $monthEnd->format('Y-m-d'))
                ->where(static function ($q) use ($monthStart): void {
                    $q->whereNull('termination_date')
                        ->orWhereDate('termination_date', '>=', $monthStart->format('Y-m-d'));
                })
                ->get();

            $policies = LeaveEntitlementPolicy::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('accrual_frequency', ['monthly', 'yearly'])
                ->when($countryFilter !== null, static fn ($q) => $q->where('country', strtoupper($countryFilter)))
                ->get();

            $updatedLedgers = [];

            foreach ($employees as $employee) {
                $empCountry = $this->resolveEmployeeCountry($employee);

                foreach ($policies as $policy) {
                    if ($countryFilter === null && strtoupper((string) $policy->country) !== $empCountry) {
                        continue;
                    }

                    $ledger = $this->findOrCreateLedgerLocked($tenantId, $employee, $policy, $fiscalYear);
                    $journal = is_array($ledger->accrual_journal) ? $ledger->accrual_journal : [];

                    if (isset($journal[$periodKey])) {
                        $this->refreshDeadlinesAndProjection($ledger, $policy, $monthEnd);
                        $ledger->save();
                        $updatedLedgers[] = $ledger;

                        continue;
                    }

                    $annualTarget = $this->resolveAnnualEntitlement($employee, $policy, $fiscalYear);
                    $fteFactor = $policy->prorate_by_fte ? $this->resolveFteRatio($employee) : '1.00000000';
                    $serviceFactor = $policy->prorate_hire_termination
                        ? $this->resolveMonthServiceFactor($employee, $policy, $monthStart, $monthEnd)
                        : '1.00000000';

                    if ($policy->accrual_frequency === 'yearly') {
                        $alreadyYearly = count($journal) > 0;
                        $increment = $alreadyYearly
                            ? '0.00000000'
                            : LeaveDecimal::mul($annualTarget, $fteFactor);
                    } else {
                        $monthlyBase = LeaveDecimal::div($annualTarget, '12');
                        $increment = LeaveDecimal::mul(LeaveDecimal::mul($monthlyBase, $fteFactor), $serviceFactor);
                    }

                    $incrementRounded = LeaveDecimal::round2($increment);
                    $newAccrued = LeaveDecimal::round2(LeaveDecimal::add((string) $ledger->accrued_ytd, $incrementRounded));

                    $journal[$periodKey] = [
                        'amount' => $incrementRounded,
                        'fte_factor' => LeaveDecimal::round2($fteFactor),
                        'service_factor' => LeaveDecimal::round2($serviceFactor),
                        'calculated_at' => now()->toIso8601String(),
                    ];

                    $ledger->policy_id = $policy->id;
                    $ledger->annual_entitlement_target = LeaveDecimal::round2(LeaveDecimal::mul($annualTarget, $fteFactor));
                    $ledger->accrued_ytd = $newAccrued;
                    $ledger->last_accrued_period = $periodKey;
                    $ledger->accrual_journal = $journal;

                    $this->refreshDeadlinesAndProjection($ledger, $policy, $monthEnd);
                    $ledger->save();

                    $this->dispatcher->publishAndDispatch(new LeaveAccrualLedgerUpdatedEvent(
                        ledgerId: (string) $ledger->id,
                        employeeId: (string) $employee->id,
                        fiscalYear: $fiscalYear,
                        leaveType: (string) $ledger->leave_type,
                        accruedYtd: (string) $ledger->accrued_ytd,
                        projectedYearEndBalance: (string) $ledger->projected_year_end_balance,
                        tenantId: $tenantId,
                        eventId: (string) Str::uuid7(),
                        occurredAt: now()->toIso8601String(),
                    ));

                    $updatedLedgers[] = $ledger;
                }
            }

            return $updatedLedgers;
        });
    }

    public function findOrCreateLedgerLocked(
        string $tenantId,
        Employee $employee,
        LeaveEntitlementPolicy $policy,
        int $fiscalYear,
    ): EmployeeLeaveAccrualLedger {
        $ledger = EmployeeLeaveAccrualLedger::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('leave_type', $policy->leave_type)
            ->lockForUpdate()
            ->first();

        if ($ledger !== null) {
            return $ledger;
        }

        $priorCarryOver = $this->resolvePriorYearCarryOver($tenantId, (string) $employee->id, $policy, $fiscalYear);
        $annualTarget = LeaveDecimal::round2(LeaveDecimal::mul(
            $this->resolveAnnualEntitlement($employee, $policy, $fiscalYear),
            $policy->prorate_by_fte ? $this->resolveFteRatio($employee) : '1.00000000'
        ));

        $ledger = EmployeeLeaveAccrualLedger::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'policy_id' => $policy->id,
            'fiscal_year' => $fiscalYear,
            'country' => $policy->country,
            'leave_type' => $policy->leave_type,
            'unit_mode' => $policy->unit_mode,
            'prior_year_carry_over' => $priorCarryOver,
            'accrued_ytd' => '0.00',
            'curtailment_reduction_ytd' => '0.00',
            'taken_ytd' => '0.00',
            'planned_approved_future' => '0.00',
            'pending_approval_amount' => '0.00',
            'projected_year_end_balance' => $priorCarryOver,
            'annual_entitlement_target' => $annualTarget,
            'expiring_carry_over_amount' => '0.00',
            'deadline_alert_level' => DeadlineAlertLevel::NONE->value,
            'accrual_journal' => [],
        ]);

        $this->refreshDeadlinesAndProjection($ledger, $policy, CarbonImmutable::now()->startOfDay());
        $ledger->save();

        return $ledger;
    }

    public function refreshDeadlinesAndProjection(
        EmployeeLeaveAccrualLedger $ledger,
        ?LeaveEntitlementPolicy $policy,
        CarbonImmutable $asOfDate,
    ): void {
        $grossAvailable = LeaveDecimal::sub(
            LeaveDecimal::add((string) $ledger->prior_year_carry_over, (string) $ledger->accrued_ytd),
            (string) $ledger->curtailment_reduction_ytd
        );
        $committed = LeaveDecimal::add(
            LeaveDecimal::add((string) $ledger->taken_ytd, (string) $ledger->planned_approved_future),
            (string) $ledger->pending_approval_amount
        );

        $ledger->projected_year_end_balance = LeaveDecimal::round2(LeaveDecimal::sub($grossAvailable, $committed));

        $priorCarryOver = (string) $ledger->prior_year_carry_over;
        if (LeaveDecimal::compare($priorCarryOver, '0') <= 0) {
            $ledger->expiring_carry_over_amount = '0.00';
            $ledger->deadline_alert_level = DeadlineAlertLevel::NONE->value;

            return;
        }

        // Unconsumed carry-over after subtracting taken_ytd
        $unconsumedCarryOver = LeaveDecimal::max('0', LeaveDecimal::sub($priorCarryOver, (string) $ledger->taken_ytd));
        $ledger->expiring_carry_over_amount = LeaveDecimal::round2($unconsumedCarryOver);

        if ($policy !== null && $policy->inps_18_month_alert_enabled && LeaveDecimal::compare($unconsumedCarryOver, '0') > 0) {
            // Italian statutory 18-month rule: residual Ferie from year (fiscal_year - 1) must be taken by June 30 of (fiscal_year + 1)
            $deadline = CarbonImmutable::create($ledger->fiscal_year + 1, 6, 30)->startOfDay();
            $ledger->expiration_deadline_date = $deadline;
            $daysLeft = (int) $asOfDate->diffInDays($deadline, false);

            $ledger->deadline_alert_level = match (true) {
                $daysLeft < 0 => DeadlineAlertLevel::EXPIRED->value,
                $daysLeft <= 60 => DeadlineAlertLevel::CRITICAL->value,
                $daysLeft <= 180 => DeadlineAlertLevel::WARNING->value,
                default => DeadlineAlertLevel::NONE->value,
            };

            return;
        }

        if ($policy !== null && $policy->expiration_months !== null && LeaveDecimal::compare($unconsumedCarryOver, '0') > 0) {
            $deadline = CarbonImmutable::create($ledger->fiscal_year, 1, 1)
                ->addMonths($policy->expiration_months)
                ->subDay()
                ->startOfDay();
            $ledger->expiration_deadline_date = $deadline;
            $daysLeft = (int) $asOfDate->diffInDays($deadline, false);

            $ledger->deadline_alert_level = match (true) {
                $daysLeft < 0 => DeadlineAlertLevel::EXPIRED->value,
                $daysLeft <= 30 => DeadlineAlertLevel::CRITICAL->value,
                $daysLeft <= 90 => DeadlineAlertLevel::WARNING->value,
                default => DeadlineAlertLevel::NONE->value,
            };

            return;
        }

        $ledger->deadline_alert_level = DeadlineAlertLevel::NONE->value;
    }

    /**
     * Effective available balance (prior carry-over + accrued YTD - curtailment - taken - planned - pending).
     */
    public function availableBalance(EmployeeLeaveAccrualLedger $ledger): string
    {
        $credits = LeaveDecimal::sub(
            LeaveDecimal::add((string) $ledger->prior_year_carry_over, (string) $ledger->accrued_ytd),
            (string) $ledger->curtailment_reduction_ytd
        );
        $debits = LeaveDecimal::add(
            LeaveDecimal::add((string) $ledger->taken_ytd, (string) $ledger->planned_approved_future),
            (string) $ledger->pending_approval_amount
        );

        return LeaveDecimal::round2(LeaveDecimal::sub($credits, $debits));
    }

    public function resolveAnnualEntitlement(Employee $employee, LeaveEntitlementPolicy $policy, int $fiscalYear): string
    {
        if (
            $policy->country === LeaveCountry::CH->value
            && $policy->leave_type === LeaveTypeCode::AnnualVacation_Ferie_Ferien->value
            && $employee->birth_date !== null
        ) {
            $ageAtYearEnd = $fiscalYear - (int) $employee->birth_date->format('Y');

            if ($ageAtYearEnd <= 20 && $policy->annual_entitlement_under_20 !== null) {
                return (string) $policy->annual_entitlement_under_20;
            }

            if ($ageAtYearEnd >= 50 && $policy->annual_entitlement_over_50 !== null) {
                return (string) $policy->annual_entitlement_over_50;
            }
        }

        return (string) $policy->annual_entitlement;
    }

    public function resolveFteRatio(Employee $employee): string
    {
        $rawFte = (string) $employee->fte_ratio;
        if (LeaveDecimal::compare($rawFte, '0') > 0) {
            return LeaveDecimal::min('1.00', LeaveDecimal::normalize($rawFte));
        }

        if ($employee->activeContract !== null && LeaveDecimal::compare((string) $employee->activeContract->fte_percentage, '0') > 0) {
            return LeaveDecimal::min('1.00', LeaveDecimal::div((string) $employee->activeContract->fte_percentage, '100'));
        }

        return '1.00000000';
    }

    public function resolveEmployeeCountry(Employee $employee): string
    {
        $metaCountry = is_array($employee->metadata) ? ($employee->metadata['country'] ?? null) : null;
        if (is_string($metaCountry) && in_array(strtoupper($metaCountry), ['CH', 'IT'], true)) {
            return strtoupper($metaCountry);
        }

        $currency = strtoupper((string) $employee->currency);
        if ($currency === 'CHF') {
            return LeaveCountry::CH->value;
        }

        $nationality = strtoupper((string) $employee->nationality);
        if ($nationality === 'CH') {
            return LeaveCountry::CH->value;
        }

        return LeaveCountry::IT->value;
    }

    private function resolveMonthServiceFactor(
        Employee $employee,
        LeaveEntitlementPolicy $policy,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): string {
        $effectiveStart = $employee->hire_date !== null
            ? CarbonImmutable::parse($employee->hire_date->format('Y-m-d'))->max($monthStart)
            : $monthStart;
        $effectiveEnd = $employee->termination_date !== null
            ? CarbonImmutable::parse($employee->termination_date->format('Y-m-d'))->min($monthEnd)
            : $monthEnd;

        if ($effectiveEnd->lt($effectiveStart)) {
            return '0.00000000';
        }

        $activeDays = (int) $effectiveStart->diffInDays($effectiveEnd) + 1;
        $totalDaysInMonth = (int) $monthStart->daysInMonth;

        if ($activeDays >= $totalDaysInMonth) {
            return '1.00000000';
        }

        if ($policy->country === LeaveCountry::IT->value) {
            // Italian CCNL 15-day rule: >= 15 calendar days worked in month grants full 1/12
            return $activeDays >= $policy->min_days_worked_in_month_for_accrual ? '1.00000000' : '0.00000000';
        }

        // Switzerland (Art. 329a CO): exact calendar day pro-rata in hire/termination month
        return LeaveDecimal::div((string) $activeDays, (string) $totalDaysInMonth);
    }

    private function resolvePriorYearCarryOver(
        string $tenantId,
        string $employeeId,
        LeaveEntitlementPolicy $policy,
        int $fiscalYear,
    ): string {
        $prev = EmployeeLeaveAccrualLedger::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('fiscal_year', $fiscalYear - 1)
            ->where('leave_type', $policy->leave_type)
            ->first();

        if ($prev === null) {
            return '0.00';
        }

        $remaining = LeaveDecimal::max('0', $this->availableBalance($prev));

        if ($policy->max_carry_over !== null) {
            $remaining = LeaveDecimal::min($remaining, (string) $policy->max_carry_over);
        }

        return LeaveDecimal::round2($remaining);
    }
}
