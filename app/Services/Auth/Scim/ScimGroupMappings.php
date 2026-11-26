<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\ScimGroupMapping;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The group to role mappings of a tenant: which role the members of a SCIM group (by display name, case-insensitive) get.
 * The roles are the ones an identity provider may grant (ProvisionableRoles, never the owner). Saving or removing a mapping
 * applies the change to the current members of the group at once (ScimGroupRoles).
 */
final class ScimGroupMappings
{
    public function __construct(
        private readonly ScimGroupRoles $roles,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * @return Collection<int, ScimGroupMapping>
     */
    public function all(string $tenantId): Collection
    {
        return ScimGroupMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('group_name')->get();
    }

    /**
     * How many members the SCIM group of each mapping has now, by lower-cased group name.
     *
     * @return array<string, int>
     */
    public function memberCounts(string $tenantId): array
    {
        $counts = [];

        $rows = DB::table('scim_groups as g')
            ->leftJoin('scim_group_members as m', 'm.scim_group_id', '=', 'g.id')
            ->where('g.tenant_id', $tenantId)
            ->groupBy('g.id', 'g.display_name')
            ->get(['g.display_name', DB::raw('count(m.id) as members')]);

        foreach ($rows as $row) {
            $counts[mb_strtolower((string) $row->display_name)] = (int) $row->members;
        }

        return $counts;
    }

    /**
     * Creates the mapping of the group, or changes its role.
     *
     * @return array{0: ScimGroupMapping, 1: bool} the mapping and whether it was created
     */
    public function upsert(string $tenantId, string $groupName, string $role, string $adminId): array
    {
        $name = trim($groupName);

        $mapping = ScimGroupMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereRaw('lower(group_name) = ?', [mb_strtolower($name)])->first();
        $created = $mapping === null;
        $previous = $mapping?->role;

        if ($mapping === null) {
            $mapping = new ScimGroupMapping(['group_name' => $name, 'role' => $role]);
            $mapping->forceFill(['tenant_id' => $tenantId])->save();
        } else {
            $mapping->forceFill(['group_name' => $name, 'role' => $role])->save();
        }

        $this->audit->record(RegistrationAudit::SCIM_GROUP_MAPPING_SAVED, $adminId, ['mapping' => $mapping->id, 'group' => $name, 'role' => $role, 'previous_role' => $previous], $tenantId);
        $this->roles->syncMembersOfGroupNamed($tenantId, $name);

        return [$mapping, $created];
    }

    public function delete(string $tenantId, string $id, string $adminId): bool
    {
        $mapping = ScimGroupMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($id)->first();

        if ($mapping === null) {
            return false;
        }

        $mapping->delete();

        $this->audit->record(RegistrationAudit::SCIM_GROUP_MAPPING_REMOVED, $adminId, ['mapping' => $id, 'group' => $mapping->group_name, 'role' => $mapping->role], $tenantId);
        $this->roles->syncMembersOfGroupNamed($tenantId, $mapping->group_name);

        return true;
    }
}
