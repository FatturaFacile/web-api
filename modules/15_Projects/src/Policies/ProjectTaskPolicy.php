<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\ProjectTask;

final class ProjectTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProjectTask $task): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProjectTask $task): bool
    {
        return $task->status !== 'completed';
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $task->actual_hours <= 0.0;
    }
}
