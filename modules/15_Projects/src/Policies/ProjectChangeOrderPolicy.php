<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectChangeOrder;

/**
 * Change orders move the contract value and the budget, so only the managers of the project decide on them.
 */
final class ProjectChangeOrderPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, ProjectChangeOrder $order): bool
    {
        return true;
    }

    public function update(User $user, ProjectChangeOrder $order): bool
    {
        return $this->manages($user, $order);
    }

    public function approve(User $user, ProjectChangeOrder $order): bool
    {
        return $this->manages($user, $order);
    }

    public function reject(User $user, ProjectChangeOrder $order): bool
    {
        return $this->manages($user, $order);
    }

    private function manages(User $user, ProjectChangeOrder $order): bool
    {
        $project = $order->project;

        return $project instanceof Project && $this->projects->manage($user, $project);
    }
}
