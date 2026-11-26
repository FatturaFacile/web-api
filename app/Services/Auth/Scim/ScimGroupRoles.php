<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\ScimGroup;
use App\Models\ScimGroupMapping;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Sso\ProvisionableRoles;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the tenant role of a member consistent with the SCIM groups they belong to and the group to role mappings of
 * the tenant (the same role set as SsoRoleMapper: ProvisionableRoles, the owner is never granted from outside).
 *
 * Rules, all on the conservative side:
 * - the role of an owner is never touched, in either direction;
 * - a mapping grants a role: the member's role before the grant is remembered (`scim_base_role`) together with the role
 *   granted (`scim_granted_role`); with several mapped groups the oldest mapping wins;
 * - when no mapped group is left the previous role is restored, but only if the current role is still the one the
 *   mapping granted: a role an administrator changed by hand afterwards, or any role that no mapping produced, stays;
 * - an administrator who changed the role after a grant makes that role the new base: the next grant starts from it.
 */
final class ScimGroupRoles
{
    public function __construct(private readonly RegistrationAudit $audit) {}

    /**
     * Applies the mappings to one member.
     *
     * @return string|null the role the member has after the call when it was changed, null when nothing changed
     */
    public function sync(string $tenantId, string $userId): ?string
    {
        return DB::transaction(function () use ($tenantId, $userId): ?string {
            $membership = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();

            if ($membership === null || $membership->role === 'owner') {
                return null;
            }

            $desired = $this->mappedRole($tenantId, $userId);
            $current = (string) $membership->role;
            $granted = $membership->scim_granted_role;
            $base = $membership->scim_base_role;

            if ($desired !== null) {
                if ($granted === $desired && $current === $desired) {
                    return null;
                }

                // A role that is not the previous grant was set by someone else: it becomes the base to return to.
                $newBase = $granted !== null && $current === $granted ? ($base ?? $current) : $current;

                DB::table('tenant_user')->where('id', $membership->id)->update([
                    'role' => $desired,
                    'scim_granted_role' => $desired,
                    'scim_base_role' => $newBase,
                    'updated_at' => now(),
                ]);

                $this->audit->record(RegistrationAudit::SCIM_ROLE_APPLIED, $userId, ['previous_role' => $current, 'role' => $desired, 'direction' => 'granted'], $tenantId);

                return $desired;
            }

            if ($granted === null) {
                return null;
            }

            $restore = $current === $granted && $base !== null && $base !== 'owner' ? (string) $base : null;

            DB::table('tenant_user')->where('id', $membership->id)->update(array_filter([
                'role' => $restore,
                'updated_at' => now(),
            ], static fn (mixed $value): bool => $value !== null) + ['scim_granted_role' => null, 'scim_base_role' => null]);

            if ($restore === null) {
                return null;
            }

            $this->audit->record(RegistrationAudit::SCIM_ROLE_APPLIED, $userId, ['previous_role' => $current, 'role' => $restore, 'direction' => 'restored'], $tenantId);

            return $restore;
        });
    }

    /**
     * Applies the mappings to every member of the groups whose name is given (after a mapping changed).
     */
    public function syncMembersOfGroupNamed(string $tenantId, string $groupName): void
    {
        $groupIds = ScimGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereRaw('lower(display_name) = ?', [mb_strtolower($groupName)])->pluck('id');

        if ($groupIds->isEmpty()) {
            return;
        }

        DB::table('scim_group_members')->where('tenant_id', $tenantId)->whereIn('scim_group_id', $groupIds)->pluck('user_id')->unique()
            ->each(fn (string $userId): ?string => $this->sync($tenantId, $userId));
    }

    /**
     * The role the mappings give the member through their groups, or null.
     */
    private function mappedRole(string $tenantId, string $userId): ?string
    {
        $names = DB::table('scim_group_members as m')
            ->join('scim_groups as g', 'g.id', '=', 'm.scim_group_id')
            ->where('m.tenant_id', $tenantId)->where('m.user_id', $userId)
            ->pluck('g.display_name')
            ->map(static fn (mixed $name): string => mb_strtolower((string) $name))
            ->all();

        if ($names === []) {
            return null;
        }

        $mappings = ScimGroupMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('created_at')->orderBy('id')->get();

        foreach ($mappings as $mapping) {
            if (in_array(mb_strtolower($mapping->group_name), $names, true) && ProvisionableRoles::isAllowed($mapping->role)) {
                return $mapping->role;
            }
        }

        return null;
    }
}
