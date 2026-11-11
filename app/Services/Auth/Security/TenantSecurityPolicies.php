<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Auth\Sso\SsoConnection;
use App\Services\Auth\TwoFactorAuthentication;

/**
 * Reads and writes the security policy of a tenant. A tenant can only tighten the platform defaults (the password rules
 * of config/auth.php): the effective minimum length is the larger of the two, a complexity rule is on when either side
 * asks for it. A tenant without a policy row follows the platform defaults, with every other control off.
 *
 * The second factor requirement for everyone stays where it already was (tenant settings `security.require_two_factor`,
 * see TwoFactorAuthentication); the policy adds the list of roles that must use it.
 */
final class TenantSecurityPolicies
{
    public function __construct(private readonly TwoFactorAuthentication $twoFactor) {}

    /**
     * The policy row of the tenant, read without the request tenant scope so it also works before a tenant context exists.
     */
    public function row(string $tenantId): ?TenantSecurityPolicy
    {
        return TenantSecurityPolicy::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    public function forTenant(Tenant|string|null $tenant): EffectivePolicy
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;
        $row = $tenantId === null ? null : $this->row($tenantId);

        return new EffectivePolicy(
            passwordMinLength: max((int) config('auth.registration.password.min_length'), (int) ($row->password_min_length ?? 0)),
            passwordRequireMixedCase: (bool) config('auth.registration.password.mixed_case') || ($row !== null && $row->password_require_mixed_case),
            passwordRequireNumbers: (bool) config('auth.registration.password.numbers') || ($row !== null && $row->password_require_numbers),
            passwordRequireSymbols: (bool) config('auth.registration.password.symbols') || ($row !== null && $row->password_require_symbols),
            passwordHistoryCount: (int) ($row->password_history_count ?? 0),
            passwordMaxAgeDays: $row?->password_max_age_days,
            lockoutMaxAttempts: (int) ($row->lockout_max_attempts ?? 0),
            lockoutMinutes: max(1, (int) ($row->lockout_minutes ?? 15)),
            sessionIdleTimeoutMinutes: $row?->session_idle_timeout_minutes,
            maxConcurrentSessions: $row?->max_concurrent_sessions,
            twoFactorRoles: array_map('strval', $row->two_factor_roles ?? []),
            ssoOnly: (bool) ($row->sso_only ?? false),
        );
    }

    /**
     * The strictest policy among the tenants of the user (the password and the lockout belong to the user, not to a tenant).
     */
    public function forUser(User $user): EffectivePolicy
    {
        $policies = $user->tenants()->get(['tenants.id'])->map(fn (Tenant $tenant): EffectivePolicy => $this->forTenant($tenant));

        if ($policies->isEmpty()) {
            return $this->forTenant(null);
        }

        return $policies->reduce(fn (?EffectivePolicy $carry, EffectivePolicy $policy): EffectivePolicy => $carry === null ? $policy : $carry->strictest($policy));
    }

    /**
     * Whether the member with this role in the tenant must use a second factor: required of everyone, or of the role.
     */
    public function requiresTwoFactor(?Tenant $tenant, ?string $role): bool
    {
        if ($tenant === null) {
            return false;
        }

        if ($this->twoFactor->isRequiredBy($tenant)) {
            return true;
        }

        return $role !== null && in_array($role, $this->forTenant($tenant)->twoFactorRoles, true);
    }

    /**
     * Creates or updates the policy row of the tenant.
     *
     * @param  array<string, mixed>  $attributes  columns of tenant_security_policies
     */
    public function save(string $tenantId, array $attributes): TenantSecurityPolicy
    {
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenantId]);
        $policy->forceFill(['tenant_id' => $tenantId])->fill($attributes)->save();

        return $policy->refresh();
    }

    /**
     * The policy of the tenant as the API presents it: what is enforced, plus whether single sign-on is ready.
     *
     * @return array<string, mixed>
     */
    public function describe(Tenant $tenant): array
    {
        $policy = $this->forTenant($tenant);
        $connection = SsoConnection::forTenant((string) $tenant->id);

        return [
            'password' => [
                'min_length' => $policy->passwordMinLength,
                'require_mixed_case' => $policy->passwordRequireMixedCase,
                'require_numbers' => $policy->passwordRequireNumbers,
                'require_symbols' => $policy->passwordRequireSymbols,
                'history_count' => $policy->passwordHistoryCount,
                'max_age_days' => $policy->passwordMaxAgeDays,
            ],
            'lockout' => [
                'max_attempts' => $policy->lockoutMaxAttempts,
                'minutes' => $policy->lockoutMinutes,
            ],
            'session' => [
                'idle_timeout_minutes' => $policy->sessionIdleTimeoutMinutes,
                'max_concurrent_sessions' => $policy->maxConcurrentSessions,
            ],
            'two_factor' => [
                'require_for' => $this->twoFactorMode($tenant),
                'roles' => $policy->twoFactorRoles,
            ],
            'sso_only' => $policy->ssoOnly,
            'sso_ready' => $connection !== null && $connection->ready,
        ];
    }

    /**
     * The mode in which the second factor is required: `all`, `roles` or `none`.
     */
    public function twoFactorMode(Tenant $tenant): string
    {
        if ($this->twoFactor->isRequiredBy($tenant)) {
            return 'all';
        }

        return $this->forTenant($tenant)->twoFactorRoles === [] ? 'none' : 'roles';
    }
}
