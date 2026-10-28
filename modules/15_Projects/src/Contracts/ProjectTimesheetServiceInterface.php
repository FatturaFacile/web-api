<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Models\ProjectTimesheet;

interface ProjectTimesheetServiceInterface
{
    public function logTime(TimesheetEntryDTO $dto): ProjectTimesheet;

    public function approveTimesheet(ProjectTimesheet $timesheet, string $approvedBy): ProjectTimesheet;
}
