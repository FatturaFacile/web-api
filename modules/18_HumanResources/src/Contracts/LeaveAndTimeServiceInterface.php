<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\RecordTimesheetDTO;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\Timesheet;

interface LeaveAndTimeServiceInterface
{
    public function initializeLeaveBalance(string $tenantId, string $employeeId, string $leaveTypeId, int $fiscalYear, float $entitlementHours, float $carryoverHours = 0.0): LeaveBalance;

    public function runMonthlyAccruals(string $tenantId, int $fiscalYear): int;

    public function submitLeaveRequest(SubmitLeaveRequestDTO $dto, ?string $actorUserId = null): LeaveRequest;

    public function approveLeaveRequest(LeaveRequest $leaveRequest, ?string $approverUserId = null): LeaveRequest;

    public function rejectLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $approverUserId = null): LeaveRequest;

    public function recordAttendance(string $tenantId, string $employeeId, string $workDate, float $regularHours = 8.0, float $overtimeHours = 0.0, ?string $projectId = null): AttendanceRecord;

    public function submitTimesheet(RecordTimesheetDTO $dto, ?string $actorUserId = null): Timesheet;

    public function approveTimesheet(Timesheet $timesheet, ?string $approverUserId = null): Timesheet;
}
