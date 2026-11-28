<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Policies;

use App\Models\User;

/**
 * Who may read and change the translations of a tenant: every member reads, only owners and administrators write the
 * tenant overrides, and a locked system key can be changed by nobody through the tenant APIs.
 */
final class TranslationPolicy
{
    private const array WRITER_ROLES = ['owner', 'admin'];

    public function viewAny(User $user, string $tenantId): bool
    {
        return $user->belongsToTenant($tenantId);
    }

    public function update(User $user, string $tenantId, bool $locked = false): bool
    {
        if ($locked) {
            return false;
        }

        return $this->role($user, $tenantId) !== null && in_array($this->role($user, $tenantId), self::WRITER_ROLES, true);
    }

    public function manageSettings(User $user, string $tenantId): bool
    {
        return $this->update($user, $tenantId);
    }

    private function role(User $user, string $tenantId): ?string
    {
        $role = $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot?->role;

        return is_string($role) ? $role : null;
    }
}
