<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

final class SalesBoundedContextPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasTenantMembership($user);
    }

    public function view(User $user, mixed $model): bool
    {
        return $this->belongsToActiveTenant($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->hasWriteRole($user);
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->belongsToActiveTenant($user, $model) && $this->hasWriteRole($user);
    }

    public function approveMarginOverride(User $user): bool
    {
        $role = $this->activeRole($user);

        return in_array($role, ['owner', 'admin', 'sales_manager', 'accountant'], true);
    }

    private function hasTenantMembership(User $user): bool
    {
        return $this->activeRole($user) !== null;
    }

    private function belongsToActiveTenant(User $user, mixed $model): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null || ! $model instanceof Model) {
            return false;
        }

        return (string) $model->getAttribute('tenant_id') === (string) $tenantId && $this->hasTenantMembership($user);
    }

    private function hasWriteRole(User $user): bool
    {
        $role = $this->activeRole($user);

        return in_array($role, ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'member'], true);
    }

    private function activeRole(User $user): ?string
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            return null;
        }

        $membership = $user->tenants()->where('tenants.id', $tenantId)->first();

        return $membership?->pivot->role ?? null;
    }
}
