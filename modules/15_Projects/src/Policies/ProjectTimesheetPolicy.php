<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * Timesheets: the author edits, submits and deletes their own sheets while draft or rejected (a project manager may
 * act for them); approval and rejection belong to the managers of the project, and nobody approves their own hours
 * except the owner or an administrator.
 */
final class ProjectTimesheetPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, ProjectTimesheet $timesheet): bool
    {
        return true;
    }

    public function update(User $user, ProjectTimesheet $timesheet): bool
    {
        return $timesheet->isEditable() && ($this->isAuthor($user, $timesheet) || $this->managesProject($user, $timesheet));
    }

    public function submit(User $user, ProjectTimesheet $timesheet): bool
    {
        return $this->update($user, $timesheet);
    }

    public function delete(User $user, ProjectTimesheet $timesheet): bool
    {
        return $this->update($user, $timesheet);
    }

    public function approve(User $user, ProjectTimesheet $timesheet): bool
    {
        if (! $this->managesProject($user, $timesheet)) {
            return false;
        }

        return ! $this->isAuthor($user, $timesheet) || $this->projects->canApproveOwnWork($user);
    }

    public function reject(User $user, ProjectTimesheet $timesheet): bool
    {
        return $this->approve($user, $timesheet);
    }

    private function isAuthor(User $user, ProjectTimesheet $timesheet): bool
    {
        return (string) $timesheet->user_id === (string) $user->id;
    }

    private function managesProject(User $user, ProjectTimesheet $timesheet): bool
    {
        $project = $timesheet->project;

        return $project instanceof Project && $this->projects->manage($user, $project);
    }
}
