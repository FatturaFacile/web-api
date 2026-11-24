<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;

/**
 * Turns the assignee settings of a node or an escalation rule into people: the reporting manager of a user (from the HR
 * organisation) and the list of approvers of a node. Everything is read inside one tenant.
 */
final class WorkflowAssigneeResolver
{
    /**
     * The user who manages the employee linked to `$userId`, or null when the user is no employee, has no manager, or the
     * manager has no user account.
     */
    public function managerUserIdOf(string $userId, string $tenantId): ?string
    {
        $managerEmployeeId = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('manager_id');

        if ($managerEmployeeId === null) {
            return null;
        }

        $managerUserId = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($managerEmployeeId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $managerUserId === null ? null : (string) $managerUserId;
    }

    /**
     * Whether the user is a member of the tenant.
     */
    public function isMember(string $userId, string $tenantId): bool
    {
        return DB::table('tenant_user')->where('user_id', $userId)->where('tenant_id', $tenantId)->exists();
    }

    /**
     * The approvers of a `user_list` node: the identifiers of its assignee setting, comma separated, without repeats.
     *
     * @return list<string>
     */
    public function userList(?string $assigneeId): array
    {
        $ids = array_map('trim', explode(',', (string) $assigneeId));

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => Str::isUuid($id))));
    }
}
