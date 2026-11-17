<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;
use App\Models\Access\AccessTeam;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teams of a company. A team may carry a custom role: a member added to the team who has no custom role yet and still
 * holds the plain `member` role receives it.
 */
final class AccessTeams
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly AccessAudit $audit,
    ) {}

    /**
     * @return list<AccessTeam>
     */
    public function list(Tenant $tenant): array
    {
        $teams = AccessTeam::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $members = $this->memberIds($tenant);

        foreach ($teams as $team) {
            $team->setAttribute('member_ids', $members[$team->id] ?? []);
        }

        return $teams->all();
    }

    /**
     * @param  array{name: string, description?: string|null, custom_role_id?: string|null}  $data
     */
    public function create(Tenant $tenant, array $data): AccessTeam
    {
        $team = AccessTeam::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'custom_role_id' => $data['custom_role_id'] ?? null,
        ]);
        $team->setAttribute('member_ids', []);

        $this->audit->record('access.team.created', (string) $tenant->id, AccessTeam::class, $team->id, null, ['custom_role_id' => $team->custom_role_id]);

        return $team;
    }

    /**
     * @param  array{name?: string, description?: string|null, custom_role_id?: string|null}  $data
     */
    public function update(Tenant $tenant, AccessTeam $team, array $data): AccessTeam
    {
        $before = ['custom_role_id' => $team->custom_role_id];

        $team->fill(array_intersect_key($data, array_flip(['name', 'description', 'custom_role_id'])))->save();
        $team->setAttribute('member_ids', $this->memberIds($tenant)[$team->id] ?? []);

        $this->audit->record('access.team.updated', (string) $tenant->id, AccessTeam::class, $team->id, $before, ['custom_role_id' => $team->custom_role_id]);

        return $team;
    }

    /**
     * Replaces the members of the team. Every user must be a member of the tenant.
     *
     * @param  list<string>  $userIds
     *
     * @throws DomainException when a user is not a member of the tenant
     * @throws SodConflictException when a custom role given to a new member breaks a segregation of duties rule
     */
    public function setMembers(Tenant $tenant, AccessTeam $team, array $userIds): AccessTeam
    {
        $userIds = array_values(array_unique($userIds));
        $members = DB::table('tenant_user')->where('tenant_id', $tenant->id)->whereIn('user_id', $userIds)->pluck('user_id')->all();

        if (count($members) !== count($userIds)) {
            throw new DomainException(__('access.errors.team_member_not_member'));
        }

        $current = DB::table('access_team_members')->where('team_id', $team->id)->whereNull('deleted_at')->pluck('user_id')->all();
        $added = array_values(array_diff($userIds, $current));
        $removed = array_values(array_diff($current, $userIds));

        DB::transaction(function () use ($tenant, $team, $added, $removed): void {
            if ($removed !== []) {
                DB::table('access_team_members')->where('team_id', $team->id)->whereIn('user_id', $removed)->delete();
            }

            foreach ($added as $userId) {
                DB::table('access_team_members')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'team_id' => $team->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->inheritTeamRole($tenant, $team, (string) $userId);
            }
        });

        $team->setAttribute('member_ids', $this->memberIds($tenant)[$team->id] ?? []);
        $this->audit->record('access.team.members_changed', (string) $tenant->id, AccessTeam::class, $team->id, null, ['added' => $added, 'removed' => $removed]);

        return $team;
    }

    public function delete(Tenant $tenant, AccessTeam $team): void
    {
        DB::transaction(function () use ($team): void {
            DB::table('access_team_members')->where('team_id', $team->id)->delete();
            $team->delete();
        });

        $this->audit->record('access.team.deleted', (string) $tenant->id, AccessTeam::class, $team->id);
    }

    /**
     * Puts a user in the given teams (an accepted invitation or an import), without replacing their other teams.
     *
     * @param  list<string>  $teamIds
     */
    public function join(Tenant $tenant, User $user, array $teamIds): void
    {
        foreach (AccessTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $teamIds)->get() as $team) {
            $exists = DB::table('access_team_members')->where('team_id', $team->id)->where('user_id', $user->id)->whereNull('deleted_at')->exists();

            if (! $exists) {
                DB::table('access_team_members')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'team_id' => $team->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function inheritTeamRole(Tenant $tenant, AccessTeam $team, string $userId): void
    {
        if ($team->custom_role_id === null) {
            return;
        }

        $membership = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $userId)->first();

        if ($membership !== null && $membership->custom_role_id === null && $membership->role === 'member') {
            $user = User::query()->find($userId);

            if ($user !== null) {
                $this->memberships->save($tenant, $user, null, null, $team->custom_role_id);
            }
        }
    }

    /**
     * @return array<string, list<string>> team id => user ids
     */
    private function memberIds(Tenant $tenant): array
    {
        $members = [];

        foreach (DB::table('access_team_members')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->get(['team_id', 'user_id']) as $row) {
            $members[$row->team_id][] = $row->user_id;
        }

        return $members;
    }
}
