<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\RecordTimesheetDTO;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\Timesheet;

/**
 * Leave balances, accruals and requests with their approval, attendance and project timesheets.
 */
interface LeaveAndTimeServiceInterface
{
    /**
     * Create the balance of a leave type for a year, or return the existing one.
     */
    public function initializeLeaveBalance(string $tenantId, string $employeeId, string $leaveTypeId, int $fiscalYear, float $entitlementHours, float $carryoverHours = 0.0): LeaveBalance;

    /**
     * Accrue the balances of a year for a month (the current month by default); a month already accrued is skipped.
     */
    public function runMonthlyAccruals(string $tenantId, int $fiscalYear, ?int $month = null): int;

    /**
     * Reserve the hours and start the approval of a leave request.
     */
    public function submitLeaveRequest(SubmitLeaveRequestDTO $dto, ?string $actorUserId = null): LeaveRequest;

    /**
     * Approve a pending request: the reserved hours become used hours.
     */
    public function approveLeaveRequest(LeaveRequest $leaveRequest, ?string $approverUserId = null): LeaveRequest;

    /**
     * Reject a pending request: the reserved hours return to the balance.
     */
    public function rejectLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $approverUserId = null): LeaveRequest;

    /**
     * Withdraw a pending request, or an approved one that has not started yet; its hours return to the balance.
     */
    public function cancelLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $actorUserId = null): LeaveRequest;

    /**
     * Record the attendance hours of a day.
     */
    public function recordAttendance(string $tenantId, string $employeeId, string $workDate, float $regularHours = 8.0, float $overtimeHours = 0.0, ?string $projectId = null): AttendanceRecord;

    /**
     * Record project hours with their labour cost.
     */
    public function submitTimesheet(RecordTimesheetDTO $dto, ?string $actorUserId = null): Timesheet;

    /**
     * Approve a timesheet and publish its labour cost.
     */
    public function approveTimesheet(Timesheet $timesheet, ?string $approverUserId = null): Timesheet;
}
