<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\LeaveManagement\Models\LeaveRequest as ManagedLeaveRequest;
use Modules\ShiftPlanning\Domain\Contracts\LeaveManagementPort;
use Modules\ShiftPlanning\Domain\ValueObjects\LeaveConflict;

/**
 * Reads the leave requests of the HR module and of the leave management module (Mod 074). Rejected and cancelled requests never conflict.
 */
final class HrLeaveManagementAdapter implements LeaveManagementPort
{
    public function conflictsFor(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hr = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'pending_approval'])
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->get()
            ->map(static fn (LeaveRequest $leave): LeaveConflict => new LeaveConflict(
                (string) $leave->id,
                $leave->status === 'approved',
                CarbonImmutable::parse($leave->start_date->format('Y-m-d')),
                CarbonImmutable::parse($leave->end_date->format('Y-m-d')),
            ))
            ->values()
            ->all();

        $mirrored = LeaveRequest::query()->where('employee_id', $employeeId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $managed = ManagedLeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'pending_approval'])
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->get()
            ->reject(static fn (ManagedLeaveRequest $leave): bool => $leave->hr_leave_request_id !== null && in_array((string) $leave->hr_leave_request_id, $mirrored, true))
            ->map(static fn (ManagedLeaveRequest $leave): LeaveConflict => new LeaveConflict(
                (string) $leave->id,
                $leave->status === 'approved',
                CarbonImmutable::parse($leave->start_date->format('Y-m-d')),
                CarbonImmutable::parse($leave->end_date->format('Y-m-d')),
            ))
            ->values()
            ->all();

        return [...$hr, ...$managed];
    }
}
