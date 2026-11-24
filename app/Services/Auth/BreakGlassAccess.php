<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;

/**
 * Emergency ("break-glass") local sign-in of Mod 004: when a tenant enforces SSO-only and the identity provider is
 * unreachable, the owners of that tenant who hold a confirmed second factor may still use the password (the second
 * factor is then asked as for any other login). Everybody else stays locked to SSO.
 */
final class BreakGlassAccess
{
    public function __construct(private readonly TwoFactorAuthentication $twoFactor) {}

    public function allows(User $user, string $tenantId): bool
    {
        return $user->getRoleInTenant($tenantId) === 'owner' && $this->twoFactor->isEnabled($user);
    }
}
