<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Support;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\CorporateGroup\Models\CompanyMember;

/**
 * Permissions per legal entity.
 *
 * Tenant owners and admins reach every company of the tenant with every permission. Every other user only reaches the
 * companies where a `company_user` membership exists, and holds the permissions of the membership role plus the
 * membership `permissions` list (`*` and `companies.*` wildcards supported).
 */
final class CorporatePermissions
{
    public const COMPANIES_VIEW = 'companies.view';

    public const COMPANIES_MANAGE = 'companies.manage';

    public const MEMBERS_MANAGE = 'companies.members.manage';

    public const INTERCOMPANY_VIEW = 'companies.intercompany.view';

    public const INTERCOMPANY_MANAGE = 'companies.intercompany.manage';

    public const CONSOLIDATION_VIEW = 'companies.consolidation.view';

    public const CONSOLIDATION_RUN = 'companies.consolidation.run';

    /** Tenant roles that reach every company. */
    private const TENANT_WIDE_ROLES = ['owner', 'admin'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::COMPANIES_VIEW, self::COMPANIES_MANAGE, self::MEMBERS_MANAGE, self::INTERCOMPANY_VIEW,
            self::INTERCOMPANY_MANAGE, self::CONSOLIDATION_VIEW, self::CONSOLIDATION_RUN,
        ];
    }

    /**
     * Default permissions of a company membership role.
     *
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        return [
            'company_admin' => self::all(),
            'accountant' => [
                self::COMPANIES_VIEW, self::INTERCOMPANY_VIEW, self::INTERCOMPANY_MANAGE,
                self::CONSOLIDATION_VIEW,
            ],
            'viewer' => [self::COMPANIES_VIEW, self::INTERCOMPANY_VIEW, self::CONSOLIDATION_VIEW],
        ];
    }

    public static function isTenantWide(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            return false;
        }

        return in_array($user->getRoleInTenant($tenantId), self::TENANT_WIDE_ROLES, true);
    }

    /**
     * Companies the user can reach (null = every company of the tenant).
     *
     * @return list<string>|null
     */
    public static function accessibleCompanyIds(?User $user): ?array
    {
        if ($user === null) {
            return [];
        }

        if (self::isTenantWide($user)) {
            return null;
        }

        return CompanyMember::query()->where('user_id', $user->id)->pluck('company_id')->map(fn ($id): string => (string) $id)->all();
    }

    public static function canAccessCompany(?User $user, string $companyId): bool
    {
        $ids = self::accessibleCompanyIds($user);

        return $ids === null || in_array($companyId, $ids, true);
    }

    /**
     * @return list<string>
     */
    public static function forUserInCompany(?User $user, string $companyId): array
    {
        if ($user === null) {
            return [];
        }

        if (self::isTenantWide($user)) {
            return self::all();
        }

        $member = CompanyMember::query()->where('user_id', $user->id)->where('company_id', $companyId)->first();
        if ($member === null) {
            return [];
        }

        $effective = self::roleMatrix()[(string) $member->role] ?? [];
        foreach ((array) ($member->permissions ?? []) as $permission) {
            if ($permission === '*' || $permission === 'companies.*') {
                $effective = array_merge($effective, self::all());
            } else {
                $effective[] = (string) $permission;
            }
        }

        return array_values(array_unique($effective));
    }

    /**
     * Permission on one company; with a null company, on at least one company the user can reach.
     */
    public static function allows(?User $user, string $permission, ?string $companyId): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::isTenantWide($user)) {
            return true;
        }

        if ($companyId !== null) {
            return in_array($permission, self::forUserInCompany($user, $companyId), true);
        }

        foreach ((array) self::accessibleCompanyIds($user) as $id) {
            if (in_array($permission, self::forUserInCompany($user, $id), true)) {
                return true;
            }
        }

        return false;
    }
}
