<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a user's roles for the mobile workspaces.
 *
 * The authoritative role of a member is the `role` column of the tenant pivot;
 * Spatie roles/permissions are honoured too when their tables exist (they are
 * not part of the default migrations).
 */
final class RoleResolver
{
    private static ?bool $spatieAvailable = null;

    /** @return Collection<int, string> */
    public function roles(User $user, ?string $tenantId = null): Collection
    {
        $tenantId ??= app(TenantContext::class)->getTenantId();

        $roles = collect();
        if ($tenantId !== null && ($pivot = $user->getRoleInTenant($tenantId)) !== null) {
            $roles->push(strtolower($pivot));
        }

        if ($this->spatieAvailable()) {
            $roles = $roles->merge($user->getRoleNames()->map(fn ($r) => strtolower((string) $r)));
        }

        return $roles->unique()->values();
    }

    /** @param  list<string>  $candidates */
    public function hasAny(User $user, array $candidates, ?string $tenantId = null): bool
    {
        return $this->roles($user, $tenantId)->intersect(array_map('strtolower', $candidates))->isNotEmpty();
    }

    public function isAdmin(User $user, ?string $tenantId = null): bool
    {
        return $this->hasAny($user, ['admin', 'owner'], $tenantId);
    }

    public function allows(User $user, string $permission): bool
    {
        return $this->spatieAvailable() && $user->can($permission);
    }

    private function spatieAvailable(): bool
    {
        return self::$spatieAvailable ??= Schema::hasTable('roles') && Schema::hasTable('permissions');
    }
}
