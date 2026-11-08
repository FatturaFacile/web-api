<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;

/**
 * Tasks: the managers of the project shape the WBS (create, delete, dependencies, assignments); a person assigned to a
 * task may also update it, typically its status, progress and actual dates.
 */
final class ProjectTaskPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, ProjectTask $task): bool
    {
        return true;
    }

    public function update(User $user, ProjectTask $task): bool
    {
        return $this->manages($user, $task)
            || $task->assignments()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $this->manages($user, $task);
    }

    public function plan(User $user, ProjectTask $task): bool
    {
        return $this->manages($user, $task);
    }

    private function manages(User $user, ProjectTask $task): bool
    {
        $project = $task->project;

        return $project instanceof Project && $this->projects->manage($user, $project);
    }
}
