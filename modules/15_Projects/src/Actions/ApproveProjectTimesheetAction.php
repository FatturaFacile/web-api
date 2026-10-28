<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\Models\ProjectTimesheet;

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
