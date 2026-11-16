<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Access\TenantRole;
use App\Models\Access\TenantTeam;
use App\Models\Tenant;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Custom roles of a tenant and the permission matrix (module by action) of every role, system or custom.
 */
final class CustomRoleService
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * @return list<string> the system roles a custom role can be based on (never the owner)
     */
    public function baseRoles(): array
    {
        return array_values(array_diff($this->memberships->roles(), ['owner']));
    }

    /**
     * @param  array{name: string, description?: string|null, base_role: string, modules?: array<string, string>}  $data
     */
    public function create(Tenant $tenant, array $data): TenantRole
    {
        $this->limits->assertCanAddCustomRole($tenant);

        $role = TenantRole::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key' => $this->uniqueKey($tenant, $data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'base_role' => $data['base_role'],
            'modules' => $this->cleanModules($data['modules'] ?? []),
        ]);

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeRole, (string) $role->id, 'role.created', null, $this->snapshot($role));

        return $role;
    }

    /**
     * Updates the role and applies the new definition to the members that hold it.
     *
     * @param  array{name?: string, description?: string|null, base_role?: string, modules?: array<string, string>}  $data
     */
    public function update(Tenant $tenant, TenantRole $role, array $data): TenantRole
    {
        return DB::transaction(function () use ($tenant, $role, $data): TenantRole {
            $old = $this->snapshot($role);

            if (isset($data['name'])) {
                $role->name = $data['name'];
            }

            if (isset($data['base_role'])) {
                $role->base_role = $data['base_role'];
            }

            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }

            if (array_key_exists('modules', $data)) {
                $role->modules = $this->cleanModules($data['modules']);
            }

            $role->save();

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeRole, (string) $role->id, 'role.updated', $old, $this->snapshot($role));

            $this->memberships->resyncCustomRole($tenant, $role, (array) $old['modules']);

            return $role;
        });
    }

    /**
     * @throws DomainException when a member, a team or an invitation still uses the role
     */
    public function delete(Tenant $tenant, TenantRole $role): void
    {
        $inUse = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('custom_role_id', $role->id)->exists()
            || TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('custom_role_id', $role->id)->exists()
            || DB::table('tenant_invitations')->where('tenant_id', $tenant->id)->where('custom_role_id', $role->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->whereNull('deleted_at')->exists();

        if ($inUse) {
            throw new DomainException('The role is still assigned to members, teams or invitations.');
        }

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeRole, (string) $role->id, 'role.deleted', $this->snapshot($role));

        $role->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeAll(Tenant $tenant): array
    {
        $counts = DB::table('tenant_user')->where('tenant_id', $tenant->id)->whereNotNull('custom_role_id')
            ->selectRaw('custom_role_id, count(*) as total')->groupBy('custom_role_id')->pluck('total', 'custom_role_id');

        return TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get()
            ->map(fn (TenantRole $role): array => [...$this->snapshot($role), 'id' => $role->id, 'key' => $role->key, 'members' => (int) ($counts[$role->id] ?? 0)])
            ->all();
    }

    /**
     * The permission matrix: for every role and module whether the role reads and writes it.
     *
     * @return array{modules: list<string>, roles: list<array<string, mixed>>}
     */
    public function matrix(Tenant $tenant): array
    {
        $rows = [];

        foreach ($this->memberships->roles() as $role) {
            $rows[] = ['key' => $role, 'name' => $role, 'custom' => false, 'base_role' => $role, 'access' => $this->accessOf($role, [])];
        }

        foreach (TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $custom) {
            $rows[] = [
                'key' => $custom->key,
                'id' => $custom->id,
                'name' => $custom->name,
                'custom' => true,
                'base_role' => $custom->base_role,
                'access' => $this->accessOf($custom->base_role, (array) $custom->modules),
            ];
        }

        return ['modules' => $this->memberships->modules(), 'roles' => $rows];
    }

    /**
     * @param  array<string, string>  $levels
     * @return array<string, array{read: bool, write: bool}>
     */
    private function accessOf(string $role, array $levels): array
    {
        $access = [];

        foreach ($this->memberships->modules() as $module) {
            $read = $this->roleIn($role, (array) config("permissions.modules.{$module}.read"));
            $write = $this->roleIn($role, (array) config("permissions.modules.{$module}.write"));

            $access[$module] = match ($levels[$module] ?? TenantMembershipService::LevelDefault) {
                TenantMembershipService::LevelBlocked => ['read' => false, 'write' => false],
                TenantMembershipService::LevelRead => ['read' => true, 'write' => false],
                TenantMembershipService::LevelWrite => ['read' => true, 'write' => true],
                default => ['read' => $read || $write, 'write' => $write],
            };
        }

        return $access;
    }

    /**
     * @param  array<int, mixed>  $roles
     */
    private function roleIn(string $role, array $roles): bool
    {
        return in_array('*', $roles, true) || in_array($role, $roles, true);
    }

    /**
     * @param  array<string, string>  $modules
     * @return array<string, string>
     */
    private function cleanModules(array $modules): array
    {
        return array_filter($modules, static fn (string $level): bool => $level !== TenantMembershipService::LevelDefault);
    }

    private function uniqueKey(Tenant $tenant, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $key = $base;
        $suffix = 2;

        while (TenantRole::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TenantRole $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'base_role' => $role->base_role,
            'modules' => (array) $role->modules,
        ];
    }
}
