<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Contracts\LeaveAvailabilityPortInterface;
use Modules\LeaveManagement\Domain\Contracts\PayrollAbsenceFeedPortInterface;
use Modules\LeaveManagement\Domain\Contracts\TimesheetAbsenceSyncPortInterface;
use Modules\LeaveManagement\Domain\Enums\LeaveRequestStatus;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Models\LeaveRequest;

/**
 * Exposes Mod 074 leave & absence data to adjacent modules:
 * - Mod 073 (ShiftPlanning) & Mod 079 (ResourcePlanning) via LeaveAvailabilityPortInterface
 * - Mod 076 (CH Payroll) & Mod 077 (IT Payroll) via PayrollAbsenceFeedPortInterface
 * - Mod 072 (Monthly Timesheets / Cartellini) via TimesheetAbsenceSyncPortInterface.
 */
final readonly class LeaveIntegrationService implements LeaveAvailabilityPortInterface, PayrollAbsenceFeedPortInterface, TimesheetAbsenceSyncPortInterface
{
    public function getAbsenceSpans(string $tenantId, array $employeeIds, string $fromDate, string $toDate, bool $approvedOnly = true): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $statuses = $approvedOnly
            ? [LeaveRequestStatus::APPROVED->value]
            : [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::PENDING_APPROVAL->value];

        $requests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', $statuses)
            ->whereDate('start_date', '<=', $toDate)
            ->whereDate('end_date', '>=', $fromDate)
            ->orderBy('start_date')
            ->get();

        $grouped = [];
        foreach ($requests as $req) {
            $grouped[(string) $req->employee_id][] = [
                'leave_request_id' => (string) $req->id,
                'leave_type' => $req->leave_type,
                'status' => $req->status,
                'start_date' => $req->start_date->format('Y-m-d'),
                'end_date' => $req->end_date->format('Y-m-d'),
                'requested_days' => (float) $req->requested_days,
                'requested_hours' => (float) $req->requested_hours,
            ];
        }

        return $grouped;
    }

    public function getMonthlyPayrollSummary(string $tenantId, int $year, int $month, ?string $country = null): array
    {
        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        $requests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveRequestStatus::APPROVED->value)
            ->when($country !== null && $country !== '', static fn ($q) => $q->where('country', strtoupper($country)))
            ->whereDate('start_date', '<=', $monthEnd->format('Y-m-d'))
            ->whereDate('end_date', '>=', $monthStart->format('Y-m-d'))
            ->with('employee:id,employee_number,full_name')
            ->get();

        $ledgers = EmployeeLeaveAccrualLedger::query()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $year)
            ->get()
            ->groupBy('employee_id');

        $byEmployee = [];
        foreach ($requests as $req) {
            $empId = (string) $req->employee_id;
            if (! isset($byEmployee[$empId])) {
                $empLedgers = $ledgers->get($empId, collect());
                $byEmployee[$empId] = [
                    'employee_id' => $empId,
                    'employee_number' => $req->employee?->employee_number,
                    'employee_name' => $req->employee?->full_name,
                    'country' => $req->country,
                    'year' => $year,
                    'month' => $month,
                    'total_absence_days' => 0.0,
                    'total_absence_hours' => 0.0,
                    'by_leave_type' => [],
                    'inps_puc_protocols' => [],
                    'suva_claims' => [],
                    'inail_claims' => [],
                    'curtailment_reduction_days' => (float) $empLedgers->sum('curtailment_reduction_ytd'),
                ];
            }

            $byEmployee[$empId]['total_absence_days'] = round($byEmployee[$empId]['total_absence_days'] + (float) $req->requested_days, 2);
            $byEmployee[$empId]['total_absence_hours'] = round($byEmployee[$empId]['total_absence_hours'] + (float) $req->requested_hours, 2);
            $byEmployee[$empId]['by_leave_type'][$req->leave_type] = round(
                ($byEmployee[$empId]['by_leave_type'][$req->leave_type] ?? 0.0) + (float) $req->requested_hours,
                2
            );

            if ($req->inps_medical_certificate_protocol_number !== null) {
                $byEmployee[$empId]['inps_puc_protocols'][] = $req->inps_medical_certificate_protocol_number;
            }
            if ($req->suva_claim_number !== null) {
                $byEmployee[$empId]['suva_claims'][] = $req->suva_claim_number;
            }
            if ($req->inail_claim_number !== null) {
                $byEmployee[$empId]['inail_claims'][] = $req->inail_claim_number;
            }
        }

        // Also include employees who had zero absences in the month but have active CH Art. 329b CO curtailments or accruals
        $allEmployees = Employee::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ledgers->keys()->all())
            ->get(['id', 'employee_number', 'full_name']);

        foreach ($allEmployees as $emp) {
            $empId = (string) $emp->id;
            if (! isset($byEmployee[$empId])) {
                $empLedgers = $ledgers->get($empId, collect());
                $firstLedger = $empLedgers->first();
                $firstCountry = $firstLedger !== null ? (string) $firstLedger->country : 'CH';
                if ($country !== null && $country !== '' && strtoupper($country) !== $firstCountry) {
                    continue;
                }
                $byEmployee[$empId] = [
                    'employee_id' => $empId,
                    'employee_number' => $emp->employee_number,
                    'employee_name' => $emp->full_name,
                    'country' => $firstCountry,
                    'year' => $year,
                    'month' => $month,
                    'total_absence_days' => 0.0,
                    'total_absence_hours' => 0.0,
                    'by_leave_type' => [],
                    'inps_puc_protocols' => [],
                    'suva_claims' => [],
                    'inail_claims' => [],
                    'curtailment_reduction_days' => (float) $empLedgers->sum('curtailment_reduction_ytd'),
                ];
            }
        }

        return array_values($byEmployee);
    }

    public function getMonthlyEmployeeAbsenceDays(string $tenantId, string $employeeId, int $year, int $month): array
    {
        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        $requests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequestStatus::APPROVED->value)
            ->whereDate('start_date', '<=', $monthEnd->format('Y-m-d'))
            ->whereDate('end_date', '>=', $monthStart->format('Y-m-d'))
            ->get();

        $entries = [];
        foreach ($requests as $req) {
            $cursor = $req->start_date->max($monthStart);
            $end = $req->end_date->min($monthEnd);
            $totalDays = max(1.0, (float) $req->requested_days);
            $hoursPerAbsenceDay = round((float) $req->requested_hours / $totalDays, 2);

            while ($cursor->lte($end)) {
                if ($cursor->dayOfWeekIso <= 5) {
                    $entries[] = [
                        'date' => $cursor->format('Y-m-d'),
                        'leave_request_id' => (string) $req->id,
                        'leave_type' => $req->leave_type,
                        'hours' => $hoursPerAbsenceDay,
                        'status' => $req->status,
                    ];
                }
                $cursor = $cursor->addDay();
            }
        }

        return $entries;
    }
}
