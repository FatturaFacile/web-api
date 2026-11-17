<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Access\TenantCustomRole;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Custom roles of a company and the matrix of what every role can read and write.
 */
final class CustomRoleService
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly AccessPlanLimits $limits,
        private readonly AccessAudit $audit,
    ) {}

    /**
     * The system roles a custom role can be based on: every assignable role except the owner.
     *
     * @return list<string>
     */
    public function baseRoles(): array
    {
        return array_values(array_filter($this->memberships->roles(), static fn (string $role): bool => $role !== 'owner'));
    }

    /**
     * @return array{roles: list<TenantCustomRole>, base_roles: list<string>, modules: list<string>, levels: list<string>}
     */
    public function overview(Tenant $tenant): array
    {
        $roles = TenantCustomRole::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $holders = DB::table('tenant_user')->where('tenant_id', $tenant->id)->whereNotNull('custom_role_id')
            ->selectRaw('custom_role_id, count(*) as holders')->groupBy('custom_role_id')->pluck('holders', 'custom_role_id');

        foreach ($roles as $role) {
            $role->setAttribute('members', (int) ($holders[$role->id] ?? 0));
        }

        return [
            'roles' => $roles->all(),
            'base_roles' => $this->baseRoles(),
            'modules' => $this->memberships->modules(),
            'levels' => $this->memberships->levels(),
        ];
    }

    /**
     * @param  array{name: string, description?: string|null, base_role: string, modules?: array<string, string>}  $data
     */
    public function create(Tenant $tenant, array $data): TenantCustomRole
    {
        $this->limits->assertRoomForCustomRole($tenant);

        $role = TenantCustomRole::query()->create([
            'tenant_id' => $tenant->id,
            'key' => $this->uniqueKey($tenant, $data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'base_role' => $data['base_role'],
            'modules' => $this->storedLevels($data['modules'] ?? []),
        ]);
        $role->setAttribute('members', 0);

        $this->audit->record('access.role.created', (string) $tenant->id, TenantCustomRole::class, $role->id, null, ['key' => $role->key, 'base_role' => $role->base_role, 'modules' => $role->modules]);

        return $role;
    }

    /**
     * Saves the given fields and writes the new levels to the memberships that hold the role.
     *
     * @param  array{name?: string, description?: string|null, base_role?: string, modules?: array<string, string>}  $data
     *
     * @throws DomainException when a holder is the last owner and the new base role is not owner
     */
    public function update(Tenant $tenant, TenantCustomRole $role, array $data): TenantCustomRole
    {
        $before = ['name' => $role->name, 'base_role' => $role->base_role, 'modules' => $role->modules];
        $previousModules = (array) $role->modules;

        DB::transaction(function () use ($tenant, $role, $data, $previousModules): void {
            $role->fill(array_intersect_key($data, array_flip(['name', 'description', 'base_role'])));

            if (array_key_exists('modules', $data)) {
                $role->modules = $this->storedLevels($data['modules']);
            }

            $role->save();
            $this->memberships->refreshCustomRole($tenant, $role, $previousModules);
        });

        $role->setAttribute('members', $this->memberships->countHolders($tenant, $role->id));
        $this->audit->record('access.role.updated', (string) $tenant->id, TenantCustomRole::class, $role->id, $before, ['name' => $role->name, 'base_role' => $role->base_role, 'modules' => $role->modules]);

        return $role;
    }

    /**
     * @throws DomainException when members still hold the role
     */
    public function delete(Tenant $tenant, TenantCustomRole $role): void
    {
        if ($this->memberships->countHolders($tenant, $role->id) > 0) {
            throw new DomainException(__('access.errors.role_in_use'));
        }

        $role->delete();

        $this->audit->record('access.role.deleted', (string) $tenant->id, TenantCustomRole::class, $role->id, ['key' => $role->key, 'base_role' => $role->base_role]);
    }

    /**
     * Read and write access of every role (system and custom) to every module, as the gate decides it.
     *
     * @return array{modules: list<string>, roles: list<array<string, mixed>>}
     */
    public function matrix(Tenant $tenant): array
    {
        $modules = $this->memberships->modules();
        $roles = [];

        foreach ($this->memberships->roles() as $key) {
            $roles[] = [
                'key' => $key,
                'name' => Str::headline($key),
                'custom' => false,
                'base_role' => $key,
                'access' => $this->access($key, $modules, []),
            ];
        }

        foreach (TenantCustomRole::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $role) {
            $roles[] = [
                'key' => $role->key,
                'id' => $role->id,
                'name' => $role->name,
                'custom' => true,
                'base_role' => $role->base_role,
                'access' => $this->access($role->base_role, $modules, (array) $role->modules),
            ];
        }

        return ['modules' => $modules, 'roles' => $roles];
    }

    /**
     * @param  list<string>  $modules
     * @param  array<string, string>  $levels
     * @return array<string, array{read: bool, write: bool}>
     */
    private function access(string $role, array $modules, array $levels): array
    {
        $access = [];

        foreach ($modules as $module) {
            $access[$module] = match ($levels[$module] ?? TenantMembershipService::LevelDefault) {
                TenantMembershipService::LevelBlocked => ['read' => false, 'write' => false],
                TenantMembershipService::LevelRead => ['read' => true, 'write' => false],
                TenantMembershipService::LevelWrite => ['read' => true, 'write' => true],
                default => ['read' => $this->roleHas($role, $module, 'read'), 'write' => $this->roleHas($role, $module, 'write')],
            };
        }

        return $access;
    }

    private function roleHas(string $role, string $module, string $access): bool
    {
        $allowed = (array) config("permissions.modules.{$module}.{$access}", []);

        return in_array('*', $allowed, true) || in_array($role, $allowed, true);
    }

    /**
     * Only the levels that differ from the default are stored.
     *
     * @param  array<string, string>  $levels
     * @return array<string, string>
     */
    private function storedLevels(array $levels): array
    {
        return array_filter($levels, static fn (string $level): bool => $level !== TenantMembershipService::LevelDefault);
    }

    private function uniqueKey(Tenant $tenant, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $key = $base;
        $suffix = 2;

        while (in_array($key, $this->memberships->roles(), true) || TenantCustomRole::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }
}
