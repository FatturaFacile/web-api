<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;
use App\Models\Access\TenantCustomRole;
use App\Models\Pivots\TenantMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Sso\ProvisionableRoles;
use App\Services\Auth\UserSessions;
use App\Support\Database\TransactionLock;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who belongs to a tenant, with which role and with which per-module access. The access of a module is one of four
 * levels stored as permissions on the tenant_user pivot: `default` (the role decides), `blocked` (`deny:<module>`),
 * `read` (`<module>.read` and `deny:<module>.write`) and `write` (`<module>.read` and `<module>.write`).
 * Permissions that are not module levels (for example `gl.*` or `payroll.read`) are kept untouched.
 */
final class TenantMembershipService
{
    public const string LevelDefault = 'default';

    public const string LevelBlocked = 'blocked';

    public const string LevelRead = 'read';

    public const string LevelWrite = 'write';

    /**
     * @return list<string>
     */
    public function levels(): array
    {
        return [self::LevelDefault, self::LevelBlocked, self::LevelRead, self::LevelWrite];
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_unique(['owner', ...ProvisionableRoles::all()]));
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return array_keys((array) config('permissions.modules'));
    }

    /**
     * The members of a tenant, suspended ones included.
     *
     * @return list<array<string, mixed>>
     */
    public function members(Tenant $tenant): array
    {
        $teams = $this->teamIdsByUser($tenant);

        $members = [];

        foreach ($tenant->users()->orderBy('users.name')->get() as $user) {
            $members[] = $this->describe($user, $this->membershipOf($user), $teams[$user->id] ?? []);
        }

        return $members;
    }

    /**
     * One member of a tenant, suspended or not, as `members()` describes it.
     *
     * @return array<string, mixed>|null
     */
    public function member(Tenant $tenant, string $userId): ?array
    {
        $user = $tenant->users()->where('users.id', $userId)->first();

        return $user === null ? null : $this->describe($user, $this->membershipOf($user), $this->teamIdsByUser($tenant)[$user->id] ?? []);
    }

    /**
     * The tenants a user belongs to, suspended memberships included.
     *
     * @return list<array<string, mixed>>
     */
    public function tenantsOf(User $user): array
    {
        $tenants = [];

        $memberships = $user->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'permissions', 'suspended_at'])
            ->orderBy('tenants.name')
            ->get();

        foreach ($memberships as $tenant) {
            $tenants[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                ...$this->describePivot($this->membershipOf($tenant)),
            ];
        }

        return $tenants;
    }

    /**
     * Gives a user access to a tenant or changes the role and the module levels of an existing membership.
     *
     * A custom role wins over `$role`: the membership takes the base role of the custom role and the module levels of the
     * custom role are written to its permissions. A role alone (or `null` for the custom role) detaches the member from
     * its custom role and clears the levels that role had set. `$modules` are applied last, as levels of this member.
     *
     * @param  array<string, string>|null  $modules  module => level; a module that is absent keeps its level, `default` clears it
     * @param  string|false|null  $customRoleId  false leaves the custom role as it is, null detaches it
     *
     * @throws DomainException when the change would leave the tenant without an owner
     * @throws SodConflictException when the change breaks a blocking segregation of duties rule without an exception
     * @throws ModelNotFoundException when the custom role is not one of the tenant
     */
    public function save(Tenant $tenant, User $user, ?string $role, ?array $modules, string|false|null $customRoleId = false): void
    {
        DB::transaction(function () use ($tenant, $user, $role, $modules, $customRoleId): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;
            $custom = is_string($customRoleId) ? $this->customRole($tenant, $customRoleId) : null;
            $permissions = $this->permissionsOf($membership?->permissions);
            $currentCustomRoleId = $membership?->custom_role_id;
            $storedCustomRoleId = $currentCustomRoleId;

            if ($custom !== null) {
                $permissions = $this->applyLevels($this->clearLevels($permissions, $this->moduleKeys($tenant, $currentCustomRoleId)), (array) $custom->modules);
                $storedCustomRoleId = $custom->id;
            } elseif ($currentCustomRoleId !== null && ($customRoleId === null || $role !== null)) {
                $permissions = $this->clearLevels($permissions, $this->moduleKeys($tenant, $currentCustomRoleId));
                $storedCustomRoleId = null;
            }

            $newRole = $custom->base_role ?? $role ?? $membership->role ?? 'member';
            $permissions = $this->applyLevels($permissions, $modules ?? []);

            if ($membership !== null && $membership->role === 'owner' && $newRole !== 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            $this->reviewSegregationOfDuties($tenant, $user, (string) $newRole, $permissions);

            if ($membership === null) {
                $tenant->users()->attach($user->id, [
                    'id' => (string) Str::uuid(),
                    'role' => $newRole,
                    'permissions' => json_encode($permissions),
                    'custom_role_id' => $storedCustomRoleId,
                ]);

                return;
            }

            $tenant->users()->updateExistingPivot($user->id, [
                'role' => $newRole,
                'permissions' => json_encode($permissions),
                'custom_role_id' => $storedCustomRoleId,
            ]);
        });
    }

    /**
     * Suspends a membership: the member keeps the account and the history but cannot act in the tenant until it is
     * reactivated. The accesses opened through the SSO of the tenant end with it.
     *
     * @throws DomainException when the member is the last active owner
     */
    public function suspend(Tenant $tenant, User $user): void
    {
        $suspended = DB::transaction(function () use ($tenant, $user): bool {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;

            if ($membership === null || $membership->suspended_at !== null) {
                return false;
            }

            if ($membership->role === 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            $tenant->users()->updateExistingPivot($user->id, ['suspended_at' => now()]);

            return true;
        });

        if ($suspended) {
            app(UserSessions::class)->revokeTenantBound($user, (string) $tenant->id);
        }
    }

    public function reactivate(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $tenant->users()->updateExistingPivot($user->id, ['suspended_at' => null]);
        });
    }

    /**
     * Writes the module levels of a changed custom role to the memberships that hold it, together with its base role.
     *
     * @param  array<string, string>  $previousModules  the module levels the role had before the change
     *
     * @throws DomainException when the change would leave the tenant without an owner
     */
    public function refreshCustomRole(Tenant $tenant, TenantCustomRole $role, array $previousModules): void
    {
        DB::transaction(function () use ($tenant, $role, $previousModules): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $holders = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('custom_role_id', $role->id)->get();
            $cleared = array_values(array_unique([...array_keys($previousModules), ...array_keys((array) $role->modules)]));

            foreach ($holders as $holder) {
                $permissions = $this->applyLevels($this->clearLevels($this->permissionsOf($holder->permissions), $cleared), (array) $role->modules);

                if ($holder->role === 'owner' && $role->base_role !== 'owner') {
                    $user = User::query()->find($holder->user_id);

                    if ($user !== null) {
                        $this->assertAnotherOwnerExists($tenant, $user);
                    }
                }

                DB::table('tenant_user')->where('id', $holder->id)->update(['role' => $role->base_role, 'permissions' => json_encode($permissions), 'updated_at' => now()]);
            }
        });
    }

    /**
     * How many memberships of the tenant hold a custom role.
     */
    public function countHolders(Tenant $tenant, string $customRoleId): int
    {
        return DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('custom_role_id', $customRoleId)->count();
    }

    /**
     * @throws DomainException when the user is the last owner of the tenant
     */
    public function remove(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;

            if ($membership === null) {
                return;
            }

            if ($membership->role === 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            $tenant->users()->detach($user->id);
        });
    }

    /**
     * @param  mixed  $permissions  the permissions column: a JSON string, an array or null
     * @return list<string>
     */
    public function permissionsOf(mixed $permissions): array
    {
        $decoded = is_string($permissions) ? json_decode($permissions, true) : $permissions;

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, string> module => level, for the modules that are not at the default level
     */
    public function levelsOf(array $permissions): array
    {
        $levels = [];

        foreach ($this->modules() as $module) {
            $level = match (true) {
                in_array("deny:{$module}", $permissions, true) => self::LevelBlocked,
                in_array("{$module}.write", $permissions, true) => self::LevelWrite,
                in_array("deny:{$module}.write", $permissions, true), in_array("{$module}.read", $permissions, true) => self::LevelRead,
                default => self::LevelDefault,
            };

            if ($level !== self::LevelDefault) {
                $levels[$module] = $level;
            }
        }

        return $levels;
    }

    /**
     * The membership a user or a tenant was loaded through (`tenant_user` row), null when it was not loaded that way.
     */
    private function membershipOf(Model $loaded): ?TenantMembership
    {
        $membership = $loaded->getRelation('pivot');

        return $membership instanceof TenantMembership ? $membership : null;
    }

    /**
     * @param  list<string>  $teamIds
     * @return array<string, mixed>
     */
    private function describe(User $user, ?TenantMembership $pivot, array $teamIds = []): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            ...$this->describePivot($pivot),
            'custom_role_id' => $pivot?->custom_role_id,
            'team_ids' => $teamIds,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describePivot(?TenantMembership $pivot): array
    {
        $permissions = $this->permissionsOf($pivot?->permissions);

        return [
            'role' => $pivot?->role,
            'suspended' => $pivot?->suspended_at !== null,
            'permissions' => $permissions,
            'modules' => $this->levelsOf($permissions),
        ];
    }

    /**
     * @return array<string, list<string>> user id => ids of the teams of the tenant
     */
    private function teamIdsByUser(Tenant $tenant): array
    {
        $teams = [];

        foreach (DB::table('access_team_members')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->get(['user_id', 'team_id']) as $row) {
            $teams[$row->user_id][] = $row->team_id;
        }

        return $teams;
    }

    /**
     * @throws ModelNotFoundException
     */
    private function customRole(Tenant $tenant, string $id): TenantCustomRole
    {
        return TenantCustomRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    /**
     * The modules a custom role (also a deleted one) set levels for.
     *
     * @return list<string>
     */
    private function moduleKeys(Tenant $tenant, mixed $customRoleId): array
    {
        if (! is_string($customRoleId)) {
            return [];
        }

        $role = TenantCustomRole::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->find($customRoleId);

        return $role === null ? [] : array_keys((array) $role->modules);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $modules
     * @return list<string>
     */
    private function clearLevels(array $permissions, array $modules): array
    {
        return $this->applyLevels($permissions, array_fill_keys($modules, self::LevelDefault));
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $levels
     * @return list<string>
     */
    private function applyLevels(array $permissions, array $levels): array
    {
        foreach ($levels as $module => $level) {
            $permissions = array_values(array_diff($permissions, ["deny:{$module}", "deny:{$module}.write", "{$module}.read", "{$module}.write"]));

            $permissions = [...$permissions, ...match ($level) {
                self::LevelBlocked => ["deny:{$module}"],
                self::LevelRead => ["{$module}.read", "deny:{$module}.write"],
                self::LevelWrite => ["{$module}.read", "{$module}.write"],
                default => [],
            }];
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Lets the segregation of duties rules (governance module, when installed) refuse the assignment or record it.
     *
     * @param  list<string>  $permissions
     *
     * @throws SodConflictException
     */
    private function reviewSegregationOfDuties(Tenant $tenant, User $user, string $role, array $permissions): void
    {
        if (app()->bound(SodAssignmentGuard::class)) {
            app(SodAssignmentGuard::class)->review((string) $tenant->id, (string) $user->id, $role, $permissions);
        }
    }

    private function assertAnotherOwnerExists(Tenant $tenant, User $except): void
    {
        $others = $tenant->users()->wherePivot('role', 'owner')->wherePivotNull('suspended_at')->where('users.id', '!=', $except->id)->exists();

        if (! $others) {
            throw new DomainException('A tenant needs at least one owner.');
        }
    }
}
