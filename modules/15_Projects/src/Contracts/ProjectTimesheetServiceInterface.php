<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * Timesheet approval flow of projects.
 */
interface ProjectTimesheetServiceInterface
{
    public function logTime(TimesheetEntryDTO $dto): ProjectTimesheet;

    /**
     * @param  array{work_date?: string, hours_worked?: string, overtime_hours?: string, task_id?: string|null, is_billable?: bool, work_summary?: string|null, role?: string|null, custom_cost_rate?: string|null, custom_charge_rate?: string|null}  $changes
     */
    public function updateTimesheet(ProjectTimesheet $timesheet, array $changes): ProjectTimesheet;

    public function submitTimesheet(ProjectTimesheet $timesheet): ProjectTimesheet;

    public function deleteTimesheet(ProjectTimesheet $timesheet): void;

    public function approveTimesheet(ProjectTimesheet $timesheet, string $approvedBy): ProjectTimesheet;

    public function rejectTimesheet(ProjectTimesheet $timesheet, string $rejectedBy, string $reason): ProjectTimesheet;
}
