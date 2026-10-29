<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\ProjectTimesheet;

final class ProjectTimesheetPolicy
{
    public function view(User $user, ProjectTimesheet $timesheet): bool
    {
        return true;
    }

    public function update(User $user, ProjectTimesheet $timesheet): bool
    {
        return $timesheet->status === 'submitted' || $timesheet->status === 'draft';
    }

    public function approve(User $user, ProjectTimesheet $timesheet): bool
    {
        return $timesheet->status === 'submitted';
    }
}
