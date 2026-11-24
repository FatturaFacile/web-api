<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;

/**
 * The budget is defined, baselined and approved by the managers of the project.
 */
final class ProjectBudgetPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, ProjectBudget $budget): bool
    {
        return true;
    }

    public function update(User $user, ProjectBudget $budget): bool
    {
        return $this->manages($user, $budget);
    }

    public function approve(User $user, ProjectBudget $budget): bool
    {
        return $this->manages($user, $budget);
    }

    private function manages(User $user, ProjectBudget $budget): bool
    {
        $project = $budget->project;

        return $project instanceof Project && $this->projects->manage($user, $project);
    }
}
