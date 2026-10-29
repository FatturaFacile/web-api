<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\Employee;

final class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->belongsToTenant($user, (string) $employee->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->belongsToTenant($user, (string) $employee->tenant_id) && ! $employee->is_locked;
    }

    public function promote(User $user, Employee $employee): bool
    {
        return $this->belongsToTenant($user, (string) $employee->tenant_id) && ! $employee->is_locked;
    }

    public function terminate(User $user, Employee $employee): bool
    {
        return $this->belongsToTenant($user, (string) $employee->tenant_id) && ! $employee->is_locked;
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->belongsToTenant($user, (string) $employee->tenant_id) && ! $employee->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
