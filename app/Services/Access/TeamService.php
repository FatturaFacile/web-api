<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Access\TenantTeam;
use App\Models\Tenant;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teams of a tenant. A team with a custom role gives that role to the members it contains.
 */
final class TeamService
{
    public function __construct(private readonly TenantMembershipService $memberships) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function describeAll(Tenant $tenant): array
    {
        $members = DB::table('tenant_team_members')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->get(['team_id', 'user_id'])->groupBy('team_id');

        return TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get()
            ->map(fn (TenantTeam $team): array => $this->describe($team, $members->get($team->id)?->pluck('user_id')->all() ?? []))
            ->all();
    }

    /**
     * @param  array{name: string, description?: string|null, custom_role_id?: string|null}  $data
     */
    public function create(Tenant $tenant, array $data): TenantTeam
    {
        $team = TenantTeam::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'custom_role_id' => $data['custom_role_id'] ?? null,
        ]);

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeTeam, (string) $team->id, 'team.created', null, $this->describe($team, []));

        return $team;
    }

    /**
     * @param  array{name?: string, description?: string|null, custom_role_id?: string|null}  $data
     */
    public function update(Tenant $tenant, TenantTeam $team, array $data): TenantTeam
    {
        $old = $this->describe($team, $this->memberIds($team));

        if (isset($data['name'])) {
            $team->name = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $team->description = $data['description'];
        }

        if (array_key_exists('custom_role_id', $data)) {
            $team->custom_role_id = $data['custom_role_id'];
        }

        $team->save();

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeTeam, (string) $team->id, 'team.updated', $old, $this->describe($team, $this->memberIds($team)));

        if (array_key_exists('custom_role_id', $data)) {
            $this->applyRole($tenant, $team, $this->memberIds($team));
        }

        return $team;
    }

    public function delete(Tenant $tenant, TenantTeam $team): void
    {
        AccessAudit::record((string) $tenant->id, AccessAudit::TypeTeam, (string) $team->id, 'team.deleted', $this->describe($team, $this->memberIds($team)));

        DB::table('tenant_team_members')->where('team_id', $team->id)->delete();
        $team->delete();
    }

    /**
     * Replaces the members of the team; only members of the tenant can belong to it.
     *
     * @param  list<string>  $userIds
     */
    public function syncMembers(Tenant $tenant, TenantTeam $team, array $userIds): TenantTeam
    {
        $valid = $tenant->users()->whereIn('users.id', $userIds)->pluck('users.id')->all();
        $current = $this->memberIds($team);
        $added = array_values(array_diff($valid, $current));
        $removed = array_values(array_diff($current, $valid));

        DB::transaction(function () use ($tenant, $team, $valid, $added, $removed): void {
            DB::table('tenant_team_members')->where('team_id', $team->id)->whereIn('user_id', $removed)->delete();

            foreach ($added as $userId) {
                DB::table('tenant_team_members')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'team_id' => $team->id, 'user_id' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeTeam, (string) $team->id, 'team.members_changed', ['removed' => $removed], ['added' => $added, 'members' => $valid]);

            $this->applyRole($tenant, $team, $added);
        });

        return $team;
    }

    /**
     * @return list<string>
     */
    public function memberIds(TenantTeam $team): array
    {
        return DB::table('tenant_team_members')->where('team_id', $team->id)->whereNull('deleted_at')->pluck('user_id')->all();
    }

    /**
     * @param  list<string>  $userIds
     */
    private function applyRole(Tenant $tenant, TenantTeam $team, array $userIds): void
    {
        if ($team->custom_role_id === null) {
            return;
        }

        foreach ($tenant->users()->whereIn('users.id', $userIds)->get() as $member) {
            if ($member->pivot->role !== 'owner') {
                $this->memberships->save($tenant, $member, null, null, (string) $team->custom_role_id);
            }
        }
    }

    /**
     * @param  list<string>  $memberIds
     * @return array<string, mixed>
     */
    private function describe(TenantTeam $team, array $memberIds): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
            'custom_role_id' => $team->custom_role_id,
            'member_ids' => $memberIds,
        ];
    }
}
