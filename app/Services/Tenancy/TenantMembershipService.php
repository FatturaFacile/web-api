<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;
use App\Models\Access\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\Access\PlanLimits;
use App\Services\Auth\Sso\ProvisionableRoles;
use App\Support\Database\TransactionLock;
use DomainException;
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
        $teams = DB::table('tenant_team_members')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->get(['team_id', 'user_id'])->groupBy('user_id');

        return $tenant->users()->orderBy('users.name')->get()
            ->map(fn (User $user): array => [
                ...$this->describe($user, $user->pivot),
                'team_ids' => $teams->get($user->id)?->pluck('team_id')->all() ?? [],
            ])
            ->all();
    }

    /**
     * The tenants a user belongs to, suspended memberships included.
     *
     * @return list<array<string, mixed>>
     */
    public function tenantsOf(User $user): array
    {
        return $user->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['id', 'role', 'permissions', 'suspended_at', 'custom_role_id'])
            ->orderBy('tenants.name')
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                ...$this->describePivot($tenant->pivot),
            ])
            ->all();
    }

    /**
     * Gives a user access to a tenant or changes the role and the module levels of an existing membership.
     *
     * @param  array<string, string>|null  $modules  module => level; a module that is absent keeps its level, `default` clears it
     *
     * @throws DomainException when the change would leave the tenant without an owner
     * @throws SodConflictException when the change breaks a blocking segregation of duties rule without an exception
     */
    public function save(Tenant $tenant, User $user, ?string $role, ?array $modules, ?string $customRoleId = null): void
    {
        DB::transaction(function () use ($tenant, $user, $role, $modules, $customRoleId): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $customRole = $customRoleId === null ? null : $this->customRole($tenant, $customRoleId);

            if ($customRole !== null) {
                $role = $customRole->base_role;
                $modules = [...(array) $customRole->modules, ...($modules ?? [])];
            }

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;

            if ($membership === null) {
                app(PlanLimits::class)->assertCanAddUsers($tenant, 1, countInvitations: false);

                $newPermissions = $this->applyLevels([], $modules ?? []);
                $this->reviewSegregationOfDuties($tenant, $user, $role ?? 'member', $newPermissions);

                $tenant->users()->attach($user->id, [
                    'id' => (string) Str::uuid(),
                    'role' => $role ?? 'member',
                    'permissions' => json_encode($newPermissions),
                    'custom_role_id' => $customRole?->id,
                ]);

                AccessAudit::record((string) $tenant->id, AccessAudit::TypeMember, (string) $user->id, 'member.added', null, [
                    'email' => $user->email, 'role' => $role ?? 'member', 'custom_role_id' => $customRole?->id, 'modules' => $this->levelsOf($newPermissions),
                ]);

                return;
            }

            if ($role !== null && $membership->role === 'owner' && $role !== 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            $newRole = $role ?? $membership->role;
            $oldPermissions = $this->permissionsOf($membership->permissions);
            $newPermissions = $this->applyLevels($oldPermissions, $modules ?? []);
            $this->reviewSegregationOfDuties($tenant, $user, (string) $newRole, $newPermissions);

            // A system role chosen on purpose detaches the member from a custom role; module changes alone keep it.
            $newCustomRoleId = $customRole !== null ? $customRole->id : ($role !== null ? null : $membership->custom_role_id);

            $tenant->users()->updateExistingPivot($user->id, [
                'role' => $newRole,
                'permissions' => json_encode($newPermissions),
                'custom_role_id' => $newCustomRoleId,
            ]);

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeMember, (string) $user->id, 'member.updated', [
                'role' => $membership->role, 'custom_role_id' => $membership->custom_role_id, 'modules' => $this->levelsOf($oldPermissions),
            ], [
                'email' => $user->email, 'role' => $newRole, 'custom_role_id' => $newCustomRoleId, 'modules' => $this->levelsOf($newPermissions),
            ]);
        });
    }

    /**
     * Applies the current definition of a custom role to every member that holds it.
     */
    public function resyncCustomRole(Tenant $tenant, TenantRole $role, array $previousModules = []): int
    {
        $count = 0;

        foreach ($tenant->users()->wherePivot('custom_role_id', $role->id)->get() as $member) {
            if ($member->pivot->role === 'owner') {
                continue;
            }

            $this->save($tenant, $member, null, [...array_fill_keys(array_keys($previousModules), self::LevelDefault), ...(array) $role->modules], (string) $role->id);
            $count++;
        }

        return $count;
    }

    /**
     * Suspends or reactivates the membership of a user: the access to the tenant stops without losing role and modules.
     *
     * @throws DomainException when the user is the last active owner of the tenant
     */
    public function setSuspended(Tenant $tenant, User $user, bool $suspended): void
    {
        DB::transaction(function () use ($tenant, $user, $suspended): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;

            if ($membership === null || ($membership->suspended_at !== null) === $suspended) {
                return;
            }

            if ($suspended && $membership->role === 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            if (! $suspended) {
                app(PlanLimits::class)->assertCanAddUsers($tenant, 1, countInvitations: false);
            }

            $tenant->users()->updateExistingPivot($user->id, ['suspended_at' => $suspended ? now() : null]);

            if ($suspended) {
                $user->tokens()->delete();
            }

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeMember, (string) $user->id, $suspended ? 'member.suspended' : 'member.reactivated', null, ['email' => $user->email]);
        });
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

            DB::table('tenant_team_members')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeMember, (string) $user->id, 'member.removed', [
                'email' => $user->email, 'role' => $membership->role,
            ]);
        });
    }

    private function customRole(Tenant $tenant, string $id): TenantRole
    {
        return TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
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
     * @return array<string, mixed>
     */
    private function describe(User $user, mixed $pivot): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            ...$this->describePivot($pivot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describePivot(mixed $pivot): array
    {
        $permissions = $this->permissionsOf($pivot?->permissions);

        return [
            'role' => $pivot?->role,
            'custom_role_id' => $pivot?->custom_role_id ?? null,
            'suspended' => ($pivot?->suspended_at ?? null) !== null,
            'permissions' => $permissions,
            'modules' => $this->levelsOf($permissions),
        ];
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
