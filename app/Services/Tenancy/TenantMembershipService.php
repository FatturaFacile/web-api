<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;
use App\Models\Tenant;
use App\Models\User;
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
        return $tenant->users()->orderBy('users.name')->get()
            ->map(fn (User $user): array => $this->describe($user, $user->pivot))
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
            ->withPivot(['id', 'role', 'permissions', 'suspended_at'])
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
    public function save(Tenant $tenant, User $user, ?string $role, ?array $modules): void
    {
        DB::transaction(function () use ($tenant, $user, $role, $modules): void {
            TransactionLock::acquire("tenant-members:{$tenant->id}");

            $membership = $tenant->users()->where('users.id', $user->id)->first()?->pivot;

            if ($membership === null) {
                $newPermissions = $this->applyLevels([], $modules ?? []);
                $this->reviewSegregationOfDuties($tenant, $user, $role ?? 'member', $newPermissions);

                $tenant->users()->attach($user->id, [
                    'id' => (string) Str::uuid(),
                    'role' => $role ?? 'member',
                    'permissions' => json_encode($newPermissions),
                ]);

                return;
            }

            if ($role !== null && $membership->role === 'owner' && $role !== 'owner') {
                $this->assertAnotherOwnerExists($tenant, $user);
            }

            $newRole = $role ?? $membership->role;
            $newPermissions = $this->applyLevels($this->permissionsOf($membership->permissions), $modules ?? []);
            $this->reviewSegregationOfDuties($tenant, $user, (string) $newRole, $newPermissions);

            $tenant->users()->updateExistingPivot($user->id, [
                'role' => $newRole,
                'permissions' => json_encode($newPermissions),
            ]);
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
