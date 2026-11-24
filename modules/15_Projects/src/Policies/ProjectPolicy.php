<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Projects\Models\Project;

/**
 * Who may do what on a project. Reading and writing at all is decided by module.access (config/permissions.php,
 * "projects"); this policy narrows the management actions (budgets, approvals, billing, recognition, closing) to the
 * managers of the company and to the project manager of the project, while every member with write access can log
 * time, record expenses and work on the tasks assigned to them.
 */
final class ProjectPolicy
{
    /** Roles of the active company that manage every project. */
    public const array ManagerRoles = ['owner', 'admin', 'project_manager'];

    /** Roles that may approve their own timesheets: in a small company the owner is often the only approver. */
    public const array SelfApprovalRoles = ['owner', 'admin'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Opening a project is a management decision.
     */
    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->manage($user, $project) && $project->isOpen();
    }

    /**
     * Only a draft project without bookings can be deleted; the controller checks the bookings.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->manage($user, $project) && $project->status === 'draft';
    }

    /**
     * Management actions on one project: the company managers or its project manager.
     */
    public function manage(User $user, Project $project): bool
    {
        return $this->isManager($user) || (string) $project->project_manager_user_id === (string) $user->id;
    }

    /**
     * Logging time or recording expenses: any member with write access, while the project is open.
     */
    public function contribute(User $user, Project $project): bool
    {
        return $project->isOpen();
    }

    /**
     * Whether the user holds a manager role in the active company.
     */
    public function isManager(User $user): bool
    {
        return in_array($this->roleOf($user), self::ManagerRoles, true);
    }

    public function canApproveOwnWork(User $user): bool
    {
        return in_array($this->roleOf($user), self::SelfApprovalRoles, true);
    }

    private function roleOf(User $user): ?string
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId === null ? null : $user->getRoleInTenant((string) $tenantId);
    }
}
