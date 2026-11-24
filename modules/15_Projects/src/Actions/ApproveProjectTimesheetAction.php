<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * Approves a submitted timesheet, locking it and booking its labour cost on the project.
 */
final readonly class ApproveProjectTimesheetAction
{
    public function __construct(
        private ProjectTimesheetServiceInterface $timesheetService
    ) {}

    public function execute(ProjectTimesheet $timesheet, string $approvedBy): ProjectTimesheet
    {
        return $this->timesheetService->approveTimesheet($timesheet, $approvedBy);
    }
}
