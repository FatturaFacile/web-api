<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\LeaveManagement\Models\LeaveRequest as ManagedLeaveRequest;
use Modules\ResourcePlanning\Domain\Contracts\LeavePort;
use Modules\ResourcePlanning\Domain\ValueObjects\LeaveSpan;

/**
 * Reads the approved leave requests of the HR module and of the leave management module (Mod 074). A request whose total hours are below a whole-day leave
 * (hours off) keeps that total and is spread over its working days by the capacity calculator.
 */
final class HrLeaveAdapter implements LeavePort
{
    public function approvedLeave(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $spans = [];

        $requests = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->get();

        foreach ($requests as $request) {
            $spans[(string) $request->employee_id][] = new LeaveSpan(
                CarbonImmutable::parse($request->start_date->format('Y-m-d')),
                CarbonImmutable::parse($request->end_date->format('Y-m-d')),
                $this->partialHours($request),
            );
        }

        $mirrored = $requests->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $managed = ManagedLeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->get();

        foreach ($managed as $request) {
            if ($request->hr_leave_request_id !== null && in_array((string) $request->hr_leave_request_id, $mirrored, true)) {
                continue;
            }

            $days = (float) $request->requested_days;
            $spans[(string) $request->employee_id][] = new LeaveSpan(
                CarbonImmutable::parse($request->start_date->format('Y-m-d')),
                CarbonImmutable::parse($request->end_date->format('Y-m-d')),
                $days > 0.0 && $days < 1.0 ? (float) $request->requested_hours : null,
            );
        }

        return $spans;
    }

    /**
     * Whole days are recorded as total_days with eight hours each in the HR module; a request that states fewer hours than
     * a day per calendar day it covers is an hourly permission.
     */
    private function partialHours(LeaveRequest $request): ?float
    {
        $days = (float) $request->total_days;

        return $days > 0.0 && $days < 1.0 ? (float) $request->total_hours : null;
    }
}
