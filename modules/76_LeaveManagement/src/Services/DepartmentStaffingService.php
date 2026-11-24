<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Enums\LeaveRequestStatus;
use Modules\LeaveManagement\Domain\ValueObjects\StaffingCheckResult;
use Modules\LeaveManagement\Models\DepartmentStaffingThreshold;
use Modules\LeaveManagement\Models\HolidayCalendar;
use Modules\LeaveManagement\Models\LeaveRequest;

/**
 * Evaluates department minimum staffing thresholds (presidio minimo) and builds the
 * Gantt absence timeline for team leads and HR managers.
 */
final readonly class DepartmentStaffingService
{
    public function __construct(
        private HolidayCatalogService $holidayCatalog,
    ) {}

    public function checkThreshold(
        string $tenantId,
        ?string $departmentId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $excludeRequestId = null,
    ): StaffingCheckResult {
        if ($departmentId === null || $departmentId === '') {
            return new StaffingCheckResult(false, null, null, 0, 0, 0.0, 0, false, []);
        }

        $department = Department::query()->where('tenant_id', $tenantId)->find($departmentId);
        $threshold = DepartmentStaffingThreshold::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->first();

        $activeEmployees = Employee::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $departmentId)
            ->whereNotIn('employment_status', ['terminated', 'retired', 'offboarding'])
            ->count();

        if ($threshold === null || $activeEmployees === 0) {
            return new StaffingCheckResult(
                breached: false,
                departmentId: $departmentId,
                departmentName: $department !== null ? $department->name : null,
                totalActiveEmployees: $activeEmployees,
                minPresentCount: $threshold !== null ? $threshold->min_present_count : 0,
                minPresentPercent: $threshold !== null ? (float) $threshold->min_present_percent : 0.0,
                effectiveMinRequired: 0,
                enforceHardBlock: $threshold !== null && (bool) $threshold->enforce_hard_block,
                criticalDays: [],
            );
        }

        $percentMin = (int) ceil(($activeEmployees * (float) $threshold->min_present_percent) / 100.0);
        $effectiveMin = max($threshold->min_present_count, $percentMin);

        $existingRequests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $departmentId)
            ->whereIn('status', [LeaveRequestStatus::PENDING_APPROVAL->value, LeaveRequestStatus::APPROVED->value])
            ->when($excludeRequestId !== null, static fn ($q) => $q->where('id', '!=', $excludeRequestId))
            ->whereDate('start_date', '<=', $endDate->format('Y-m-d'))
            ->whereDate('end_date', '>=', $startDate->format('Y-m-d'))
            ->get(['employee_id', 'start_date', 'end_date']);

        $criticalDays = [];
        $cursor = $startDate->startOfDay();
        $limit = $endDate->startOfDay();

        while ($cursor->lte($limit)) {
            if ($cursor->dayOfWeekIso <= 5) {
                $absentIds = [];
                foreach ($existingRequests as $req) {
                    if ($cursor->gte($req->start_date->startOfDay()) && $cursor->lte($req->end_date->startOfDay())) {
                        $absentIds[$req->employee_id] = true;
                    }
                }
                // Include the candidate request (+1 absent)
                $projectedAbsent = count($absentIds) + 1;
                $projectedPresent = max(0, $activeEmployees - $projectedAbsent);

                if ($projectedPresent < $effectiveMin) {
                    $criticalDays[] = [
                        'date' => $cursor->format('Y-m-d'),
                        'present_count' => $projectedPresent,
                        'absent_count' => $projectedAbsent,
                        'required_min' => $effectiveMin,
                    ];
                }
            }

            $cursor = $cursor->addDay();
        }

        return new StaffingCheckResult(
            breached: $criticalDays !== [],
            departmentId: $departmentId,
            departmentName: $department?->name,
            totalActiveEmployees: $activeEmployees,
            minPresentCount: $threshold->min_present_count,
            minPresentPercent: (float) $threshold->min_present_percent,
            effectiveMinRequired: $effectiveMin,
            enforceHardBlock: (bool) $threshold->enforce_hard_block,
            criticalDays: $criticalDays,
        );
    }

    /**
     * Build the Gantt timeline matrix for a department (or all departments) across a date window.
     *
     * @return array<string, mixed>
     */
    public function buildDepartmentGantt(
        string $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $departmentId = null,
        string $country = 'CH',
        ?string $cantonOrProvince = null,
    ): array {
        $employees = Employee::query()
            ->where('tenant_id', $tenantId)
            ->when($departmentId !== null && $departmentId !== '', static fn ($q) => $q->where('department_id', $departmentId))
            ->whereNotIn('employment_status', ['terminated', 'retired'])
            ->with('department:id,name,code')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_number', 'department_id', 'fte_ratio', 'currency', 'nationality']);

        $employeeIds = $employees->pluck('id')->all();

        $requests = $employeeIds === []
            ? collect()
            : LeaveRequest::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('status', [LeaveRequestStatus::PENDING_APPROVAL->value, LeaveRequestStatus::APPROVED->value])
                ->whereDate('start_date', '<=', $to->format('Y-m-d'))
                ->whereDate('end_date', '>=', $from->format('Y-m-d'))
                ->orderBy('start_date')
                ->get();

        $threshold = $departmentId !== null && $departmentId !== ''
            ? DepartmentStaffingThreshold::query()
                ->where('tenant_id', $tenantId)
                ->where('department_id', $departmentId)
                ->where('is_active', true)
                ->first()
            : null;

        $holidaysByDate = $this->resolveHolidaysMap($tenantId, $from, $to, $country, $cantonOrProvince);
        $totalHeadcount = $employees->count();
        $effectiveMin = $threshold !== null && $totalHeadcount > 0
            ? max($threshold->min_present_count, (int) ceil(($totalHeadcount * (float) $threshold->min_present_percent) / 100.0))
            : 0;

        $days = [];
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->format('Y-m-d');
            $absentCount = 0;

            foreach ($requests as $req) {
                if ($cursor->gte($req->start_date->startOfDay()) && $cursor->lte($req->end_date->startOfDay())) {
                    $absentCount++;
                }
            }

            $presentCount = max(0, $totalHeadcount - $absentCount);
            $isWeekend = $cursor->dayOfWeekIso >= 6;
            $holiday = $holidaysByDate[$dateStr] ?? null;

            $days[] = [
                'date' => $dateStr,
                'weekday' => $cursor->format('D'),
                'is_weekend' => $isWeekend,
                'holiday' => $holiday,
                'absent_count' => $absentCount,
                'present_count' => $presentCount,
                'required_min' => $effectiveMin,
                'threshold_breached' => ! $isWeekend && $holiday === null && $effectiveMin > 0 && $presentCount < $effectiveMin,
            ];

            $cursor = $cursor->addDay();
        }

        $requestsByEmp = $requests->groupBy('employee_id');

        $rows = $employees->map(static function (Employee $emp) use ($requestsByEmp): array {
            $empReqs = $requestsByEmp->get($emp->id, collect());

            return [
                'employee_id' => $emp->id,
                'employee_number' => $emp->employee_number,
                'employee_name' => $emp->full_name,
                'department_id' => $emp->department_id,
                'department_name' => $emp->department?->name,
                'fte_ratio' => (float) ($emp->fte_ratio ?? 1.0),
                'absences' => $empReqs->map(static fn (LeaveRequest $r): array => [
                    'id' => $r->id,
                    'request_number' => $r->request_number,
                    'leave_type' => $r->leave_type,
                    'start_date' => $r->start_date->format('Y-m-d'),
                    'end_date' => $r->end_date->format('Y-m-d'),
                    'half_day_or_hourly_duration' => $r->half_day_or_hourly_duration,
                    'requested_days' => (float) $r->requested_days,
                    'requested_hours' => (float) $r->requested_hours,
                    'status' => $r->status,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'department_id' => $departmentId,
            'country' => strtoupper($country),
            'canton_or_province_code' => $cantonOrProvince,
            'total_employees' => $totalHeadcount,
            'threshold' => $threshold !== null ? [
                'min_present_count' => $threshold->min_present_count,
                'min_present_percent' => (float) $threshold->min_present_percent,
                'effective_min_required' => $effectiveMin,
                'enforce_hard_block' => (bool) $threshold->enforce_hard_block,
            ] : null,
            'days' => $days,
            'employees' => $rows,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveHolidaysMap(
        string $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $country,
        ?string $cantonOrProvince,
    ): array {
        $years = array_values(array_unique([$from->year, $to->year]));
        $upperCountry = strtoupper($country);
        $map = [];

        $stored = HolidayCalendar::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $upperCountry)
            ->whereIn('year', $years)
            ->where('is_active', true)
            ->when($cantonOrProvince !== null && $cantonOrProvince !== '', static fn ($q) => $q->where('canton_or_province_code', strtoupper($cantonOrProvince)))
            ->get();

        foreach ($stored as $cal) {
            foreach ($cal->holidays ?? [] as $entry) {
                if (isset($entry['date'])) {
                    $map[(string) $entry['date']] = $entry;
                }
            }
        }

        if ($map === []) {
            foreach ($years as $year) {
                $generated = $this->holidayCatalog->generateForJurisdiction($upperCountry, $year, $cantonOrProvince);
                foreach ($generated as $item) {
                    $map[$item->date] = $item->toArray();
                }
            }
        }

        return $map;
    }
}
