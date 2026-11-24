<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\Department;

final class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Department $department): bool
    {
        return $this->belongsToTenant($user, (string) $department->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Department $department): bool
    {
        return $this->belongsToTenant($user, (string) $department->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
