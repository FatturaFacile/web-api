<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\ShiftPlanning\Domain\Contracts\LeaveManagementPort;
use Modules\ShiftPlanning\Domain\ValueObjects\LeaveConflict;

/**
 * Reads the leave requests of the HR module (Mod 074). Rejected and cancelled requests never conflict.
 */
final class HrLeaveManagementAdapter implements LeaveManagementPort
{
    public function conflictsFor(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LeaveRequest::query()
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
    }
}
