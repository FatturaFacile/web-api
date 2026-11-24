<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * Logs hours on a project as a draft, or submitted for approval when the entry asks for it.
 */
final readonly class SubmitProjectTimesheetAction
{
    public function __construct(
        private ProjectTimesheetServiceInterface $timesheetService
    ) {}

    public function execute(TimesheetEntryDTO $dto): ProjectTimesheet
    {
        return $this->timesheetService->logTime($dto);
    }
}
