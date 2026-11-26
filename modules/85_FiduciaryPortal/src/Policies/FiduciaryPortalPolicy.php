<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

final class FiduciaryPortalPolicy
{
    public const string ABILITY_VIEW = 'fiduciary-portal.view';

    public const string ABILITY_VERIFY = 'fiduciary-portal.verify';

    public const string ABILITY_TRANSCODE = 'fiduciary-portal.transcode';

    public const string ABILITY_EXPORT = 'fiduciary-portal.export';

    public const string ABILITY_LOCK_PERIOD = 'fiduciary-portal.lock-period';

    public const string ABILITY_NOTES = 'fiduciary-portal.notes';

    public const string ABILITY_DOSSIER = 'fiduciary-portal.dossier';

    private const array VIEW_ROLES = ['owner', 'admin', 'accountant', 'accountant_readonly', 'external_fiduciary', 'finance_director'];

    private const array WRITE_ROLES = ['owner', 'admin', 'accountant', 'external_fiduciary', 'finance_director'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function view(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.view', self::VIEW_ROLES);
    }

    public function verify(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.verify', self::WRITE_ROLES);
    }

    public function transcode(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.transcode', self::WRITE_ROLES);
    }

    public function export(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.export', self::WRITE_ROLES);
    }

    public function lockPeriod(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.lock_period', self::WRITE_ROLES);
    }

    public function notes(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.notes', self::WRITE_ROLES);
    }

    public function dossier(User $user): bool
    {
        return $this->hasAbility($user, 'fiduciary_portal.dossier', self::VIEW_ROLES);
    }

    /**
     * @param  list<string>  $grantingRoles
     */
    private function hasAbility(User $user, string $permissionSlug, array $grantingRoles): bool
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $user->tenant_id;
        if ($tenantId === null) {
            return false;
        }

        if ($user->hasTenantPermission($tenantId, 'fiduciary_portal.*', $grantingRoles)) {
            return true;
        }

        return $user->hasTenantPermission($tenantId, $permissionSlug, $grantingRoles);
    }
}
