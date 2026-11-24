<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveRequestStatus;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\ValueObjects\ComportoOrCurtailmentEvaluation;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;
use Modules\LeaveManagement\Models\LeaveRequest;

/**
 * Evaluates Italian periodo di comporto (CCNL rolling sick-day ceiling) and
 * Swiss Art. 329a / Art. 329b CO Ferienkürzung (1/12 per full month of absence after grace period).
 */
final readonly class LeaveComportoAndCurtailmentService
{
    public function __construct(
        private LeaveAccrualService $accrualService,
    ) {}

    public function evaluate(
        string $tenantId,
        string $employeeId,
        int $fiscalYear,
        ?string $countryOverride = null,
        bool $applyCurtailment = false,
    ): ComportoOrCurtailmentEvaluation {
        $employee = Employee::query()
            ->where('tenant_id', $tenantId)
            ->with('activeContract')
            ->findOrFail($employeeId);

        $country = $countryOverride !== null
            ? strtoupper($countryOverride)
            : $this->accrualService->resolveEmployeeCountry($employee);

        $sickPolicy = LeaveEntitlementPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country)
            ->where('leave_type', LeaveTypeCode::SickLeave_Malattia->value)
            ->where('is_active', true)
            ->first();

        $vacPolicy = LeaveEntitlementPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country)
            ->where('leave_type', LeaveTypeCode::AnnualVacation_Ferie_Ferien->value)
            ->where('is_active', true)
            ->first();

        $comporto = $this->evaluateItalianComporto($tenantId, $employee, $country, $sickPolicy);
        $curtailment = $this->evaluateSwissCurtailment($tenantId, $employee, $fiscalYear, $country, $vacPolicy, $applyCurtailment);
        $alerts = $this->collectDeadlineAlerts($tenantId, (string) $employee->id, $fiscalYear);

        return new ComportoOrCurtailmentEvaluation(
            employeeId: (string) $employee->id,
            employeeName: (string) $employee->full_name,
            fiscalYear: $fiscalYear,
            country: $country,
            italianComporto: $comporto,
            swissCurtailment: $curtailment,
            vacationDeadlineAlerts: $alerts,
        );
    }

    /**
     * @return array{
     *     applicable: bool,
     *     window_months: int,
     *     max_days: int,
     *     sick_days_in_window: float,
     *     remaining_days: float,
     *     utilization_percent: float,
     *     status: string
     * }
     */
    private function evaluateItalianComporto(
        string $tenantId,
        Employee $employee,
        string $country,
        ?LeaveEntitlementPolicy $sickPolicy,
    ): array {
        $windowMonths = $sickPolicy !== null ? $sickPolicy->comporto_window_months : 12;
        $maxDays = $sickPolicy !== null && $sickPolicy->comporto_max_days !== null ? $sickPolicy->comporto_max_days : 180;
        $windowStart = CarbonImmutable::now()->subMonths($windowMonths)->startOfDay();

        $sickDays = (float) LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->where('leave_type', LeaveTypeCode::SickLeave_Malattia->value)
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::PENDING_APPROVAL->value])
            ->whereDate('end_date', '>=', $windowStart->format('Y-m-d'))
            ->get(['start_date', 'end_date', 'requested_days'])
            ->sum(static function (LeaveRequest $r) use ($windowStart): float {
                if ($r->start_date->gte($windowStart)) {
                    return (float) $r->requested_days;
                }

                return max(0.0, (float) ($windowStart->diffInDays($r->end_date) + 1));
            });

        $utilization = $maxDays > 0 ? round(($sickDays / $maxDays) * 100.0, 2) : 0.0;
        $remaining = max(0.0, round($maxDays - $sickDays, 2));

        $status = match (true) {
            $sickDays >= $maxDays => 'exceeded',
            $utilization >= 90.0 => 'critical_90',
            $utilization >= 75.0 => 'warning_75',
            default => 'ok',
        };

        return [
            'applicable' => $country === LeaveCountry::IT->value,
            'window_months' => $windowMonths,
            'max_days' => $maxDays,
            'sick_days_in_window' => round($sickDays, 2),
            'remaining_days' => $remaining,
            'utilization_percent' => $utilization,
            'status' => $status,
        ];
    }

    /**
     * Art. 329b CO Ferienkürzung:
     * - Non-fault absence (illness, accident, military/civil): 1st full month is grace (Franchigia); each additional full month reduces by 1/12.
     * - Pregnancy absence: 2 full months grace; each additional full month reduces by 1/12.
     * - Fault absence: 0 months grace; each full month reduces by 1/12.
     *
     * @return array{
     *     applicable: bool,
     *     art329a_base_entitlement_days: float,
     *     grace_months: int,
     *     qualifying_absence_days: float,
     *     full_absence_months: int,
     *     reducible_months: int,
     *     reduction_fraction: string,
     *     reduction_days: float,
     *     net_entitlement_days: float,
     *     applied_to_ledger: bool
     * }
     */
    private function evaluateSwissCurtailment(
        string $tenantId,
        Employee $employee,
        int $fiscalYear,
        string $country,
        ?LeaveEntitlementPolicy $vacPolicy,
        bool $applyCurtailment,
    ): array {
        $baseEntitlement = $vacPolicy !== null
            ? (float) $this->accrualService->resolveAnnualEntitlement($employee, $vacPolicy, $fiscalYear)
            : $this->defaultArt329aDays($employee, $fiscalYear);

        $graceMonths = $vacPolicy !== null ? $vacPolicy->ch_curtailment_grace_months : 1;
        $yearStart = CarbonImmutable::create($fiscalYear, 1, 1)->startOfDay();
        $yearEnd = CarbonImmutable::create($fiscalYear, 12, 31)->startOfDay();

        $absences = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->whereIn('leave_type', [
                LeaveTypeCode::SickLeave_Malattia->value,
                LeaveTypeCode::Accident_Infortunio_SUVA_INAIL->value,
                LeaveTypeCode::CH_MilitaryCivilService_EO_APG->value,
                LeaveTypeCode::UnpaidLeave->value,
            ])
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value])
            ->whereDate('start_date', '<=', $yearEnd->format('Y-m-d'))
            ->whereDate('end_date', '>=', $yearStart->format('Y-m-d'))
            ->get();

        $nonFaultDays = 0.0;
        $pregnancyDays = 0.0;
        $faultDays = 0.0;

        foreach ($absences as $absence) {
            $overlapStart = $absence->start_date->max($yearStart);
            $overlapEnd = $absence->end_date->min($yearEnd);
            $days = max(0.0, (float) ($overlapStart->diffInDays($overlapEnd) + 1));

            if ($absence->is_fault_absence) {
                $faultDays += $days;
            } elseif ($absence->is_pregnancy_related) {
                $pregnancyDays += $days;
            } else {
                $nonFaultDays += $days;
            }
        }

        $reducibleNonFault = max(0, intdiv((int) floor($nonFaultDays), 30) - $graceMonths);
        $reduciblePregnancy = max(0, intdiv((int) floor($pregnancyDays), 30) - 2);
        $reducibleFault = intdiv((int) floor($faultDays), 30);

        $totalReducibleMonths = min(12, $reducibleNonFault + $reduciblePregnancy + $reducibleFault);
        $totalQualifyingDays = $nonFaultDays + $pregnancyDays + $faultDays;
        $fullMonths = intdiv((int) floor($totalQualifyingDays), 30);

        $reductionDays = (float) LeaveDecimal::round2(
            LeaveDecimal::div(LeaveDecimal::mul((string) $baseEntitlement, (string) $totalReducibleMonths), '12')
        );
        $netEntitlement = max(0.0, round($baseEntitlement - $reductionDays, 2));

        $applied = false;
        if ($applyCurtailment && $country === LeaveCountry::CH->value && $reductionDays > 0.0) {
            $ledger = EmployeeLeaveAccrualLedger::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->where('fiscal_year', $fiscalYear)
                ->where('leave_type', LeaveTypeCode::AnnualVacation_Ferie_Ferien->value)
                ->first();

            if ($ledger !== null) {
                $ledger->curtailment_reduction_ytd = LeaveDecimal::round2((string) $reductionDays);
                $this->accrualService->refreshDeadlinesAndProjection($ledger, $vacPolicy, CarbonImmutable::now()->startOfDay());
                $ledger->save();
                $applied = true;
            }
        }

        return [
            'applicable' => $country === LeaveCountry::CH->value,
            'art329a_base_entitlement_days' => round($baseEntitlement, 2),
            'grace_months' => $graceMonths,
            'qualifying_absence_days' => round($totalQualifyingDays, 2),
            'full_absence_months' => $fullMonths,
            'reducible_months' => $totalReducibleMonths,
            'reduction_fraction' => "{$totalReducibleMonths}/12",
            'reduction_days' => $reductionDays,
            'net_entitlement_days' => $netEntitlement,
            'applied_to_ledger' => $applied,
        ];
    }

    private function defaultArt329aDays(Employee $employee, int $fiscalYear): float
    {
        if ($employee->birth_date === null) {
            return 20.0;
        }

        $age = $fiscalYear - (int) $employee->birth_date->format('Y');

        return ($age <= 20 || $age >= 50) ? 25.0 : 20.0;
    }

    /**
     * @return list<array{
     *     leave_type: string,
     *     fiscal_year: int,
     *     deadline_date: string,
     *     expiring_amount: float,
     *     alert_level: string
     * }>
     */
    private function collectDeadlineAlerts(string $tenantId, string $employeeId, int $fiscalYear): array
    {
        return EmployeeLeaveAccrualLedger::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('fiscal_year', $fiscalYear)
            ->whereNotNull('expiration_deadline_date')
            ->where('expiring_carry_over_amount', '>', 0)
            ->get()
            ->map(static fn (EmployeeLeaveAccrualLedger $l): array => [
                'leave_type' => $l->leave_type,
                'fiscal_year' => $l->fiscal_year,
                'deadline_date' => $l->expiration_deadline_date?->format('Y-m-d') ?? '',
                'expiring_amount' => (float) $l->expiring_carry_over_amount,
                'alert_level' => $l->deadline_alert_level,
            ])
            ->values()
            ->all();
    }
}
