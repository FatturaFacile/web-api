<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\ProjectBudget;

final class ProjectBudgetPolicy
{
    public function view(User $user, ProjectBudget $budget): bool
    {
        return true;
    }

    public function update(User $user, ProjectBudget $budget): bool
    {
        return $budget->status !== 'approved';
    }
}
