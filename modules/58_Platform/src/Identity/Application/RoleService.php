<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use App\Exceptions\SodConflictException;
use App\Models\User;
use App\Services\Tenancy\AccessAudit;
use App\Services\Tenancy\SodAssignmentGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Identity\Domain\Enums\SystemRole;
use Modules\Platform\Identity\Domain\Events\RoleAssigned;
use Modules\Platform\Identity\Domain\Events\RoleCreated;
use Modules\Platform\Identity\Domain\Events\RoleUpdated;
use Modules\Platform\Identity\Domain\Exceptions\PrivilegeEscalationException;
use Modules\Platform\Identity\Domain\Services\PrivilegeEscalationGuard;
use Modules\Platform\Identity\Domain\ValueObjects\AbacCondition;
use Modules\Platform\Identity\Infrastructure\Models\AbacRuleRecord;
use Modules\Platform\Identity\Infrastructure\Models\FieldPolicy;
use Modules\Platform\Identity\Infrastructure\Models\Role;
use Modules\Platform\Identity\Infrastructure\Models\RoleAssignment;
use Modules\Platform\Identity\Infrastructure\Models\RolePermission;

/** Commands of the role aggregate: every change is audited and invalidates the permission cache of the tenant. */
final class RoleService
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly EffectivePermissionResolver $resolver,
        private readonly PrivilegeEscalationGuard $escalation,
        private readonly AccessAudit $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function create(string $tenantId, string $name, ?string $description, array $permissions, ?string $key = null): Role
    {
        $this->assertKnown($permissions);

        return DB::transaction(function () use ($tenantId, $name, $description, $permissions, $key): Role {
            $role = Role::create([
                'tenant_id' => $tenantId,
                'key' => $this->uniqueKey($tenantId, $key ?? Str::slug($name, '_')),
                'name' => $name,
                'description' => $description,
                'is_system' => false,
            ]);
            $this->writePermissions($role, $tenantId, $permissions);
            event(new RoleCreated($tenantId, (string) auth()->id(), (string) $role->id, (string) auth()->id()));
            $this->resolver->bump($tenantId);

            return $role;
        });
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public function update(Role $role, ?string $name, ?string $description, ?array $permissions): Role
    {
        $this->assertEditable($role);
        $tenantId = (string) $role->tenant_id;

        if ($permissions !== null) {
            $this->assertKnown($permissions);
        }

        return DB::transaction(function () use ($role, $tenantId, $name, $description, $permissions): Role {
            $before = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('permission')->all()];
            $role->fill(array_filter(['name' => $name, 'description' => $description], static fn ($v): bool => $v !== null))->save();

            if ($permissions !== null) {
                $role->permissions()->delete();
                $this->writePermissions($role, $tenantId, $permissions);
            }

            event(new RoleUpdated($tenantId, (string) auth()->id(), (string) $role->id, (string) auth()->id()));
            $this->resolver->bump($tenantId);

            return $role->refresh();
        });
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        if ($role->assignments()->exists()) {
            throw new DomainException('The role is still assigned to users.');
        }

        $tenantId = (string) $role->tenant_id;
        DB::transaction(function () use ($role, $tenantId): void {
            $role->delete();
            $this->audit->record('access.role.deleted', $tenantId, Role::class, $role->id, ['key' => $role->key]);
            $this->resolver->bump($tenantId);
        });
    }

    public function clone(Role $source, string $tenantId, string $name): Role
    {
        $permissions = $source->permissions()->pluck('permission')->all();
        $copy = $this->create($tenantId, $name, $source->description, $permissions);

        foreach ($source->abacRules as $rule) {
            AbacRuleRecord::create(['tenant_id' => $tenantId, 'role_id' => $copy->id, 'permission' => $rule->permission, 'effect' => $rule->effect, 'conditions' => $rule->conditions]);
        }
        foreach ($source->fieldPolicies as $policy) {
            FieldPolicy::create(['tenant_id' => $tenantId, 'role_id' => $copy->id, 'resource' => $policy->resource, 'field' => $policy->field, 'visibility' => $policy->visibility, 'kind' => $policy->kind]);
        }
        $this->resolver->bump($tenantId);

        return $copy;
    }

    /**
     * @param  list<array{permission: string, effect: string, conditions: list<array{attribute: string, operator: string, value: mixed}>}>  $rules
     */
    public function upsertAbacRules(Role $role, array $rules): void
    {
        $this->assertEditable($role);
        $tenantId = (string) $role->tenant_id;

        foreach ($rules as $rule) {
            foreach ($rule['conditions'] as $c) {
                if (! AbacCondition::isDefinitionValid($c['attribute'], $c['operator'])) {
                    throw new DomainException("Unsupported ABAC condition [{$c['attribute']} {$c['operator']}].");
                }
            }
        }

        DB::transaction(function () use ($role, $tenantId, $rules): void {
            $role->abacRules()->delete();
            foreach ($rules as $rule) {
                AbacRuleRecord::create(['tenant_id' => $tenantId, 'role_id' => $role->id, 'permission' => $rule['permission'], 'effect' => $rule['effect'], 'conditions' => $rule['conditions']]);
            }
            $this->audit->record('access.role.abac_updated', $tenantId, Role::class, $role->id, null, ['rules' => count($rules)]);
            $this->resolver->bump($tenantId);
        });
    }

    /**
     * @param  list<array{resource: string, field: string, visibility: string, kind?: string}>  $policies
     */
    public function upsertFieldPolicies(Role $role, array $policies): void
    {
        $this->assertEditable($role);
        $tenantId = (string) $role->tenant_id;

        DB::transaction(function () use ($role, $tenantId, $policies): void {
            $role->fieldPolicies()->delete();
            foreach ($policies as $p) {
                FieldPolicy::create(['tenant_id' => $tenantId, 'role_id' => $role->id, 'resource' => $p['resource'], 'field' => $p['field'], 'visibility' => $p['visibility'], 'kind' => $p['kind'] ?? 'amount']);
            }
            $this->audit->record('access.role.field_policies_updated', $tenantId, Role::class, $role->id, null, ['policies' => count($policies)]);
            $this->resolver->bump($tenantId);
        });
    }

    /**
     * Replaces the roles of a user (optionally for one company), after the escalation and segregation-of-duties checks.
     *
     * @param  list<string>  $roleIds
     *
     * @throws PrivilegeEscalationException
     * @throws SodConflictException
     */
    public function assign(User $actor, User $target, string $tenantId, array $roleIds, ?string $companyId = null): void
    {
        $roles = Role::query()->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new DomainException('Unknown role.');
        }

        $current = RoleAssignment::query()->where('user_id', $target->id)->with('role')->get();
        $toSystem = static fn ($collection): array => $collection->map(static fn ($r) => SystemRole::tryFrom($r->key))->filter()->values()->all();

        $actorPivotRole = SystemRole::tryFrom((string) $actor->getRoleInTenant($tenantId));
        $actorRoles = [...($actorPivotRole === null ? [] : [$actorPivotRole]), ...$toSystem(RoleAssignment::query()->where('user_id', $actor->id)->with('role')->get()->pluck('role')->filter())];

        $currentRoles = $toSystem($current->pluck('role')->filter());
        $pivotRole = SystemRole::tryFrom((string) $target->getRoleInTenant($tenantId));
        if ($pivotRole !== null) {
            $currentRoles[] = $pivotRole;
        }

        $this->escalation->assertCanAssign(
            (string) $actor->id,
            (string) $target->id,
            $actorRoles,
            $toSystem($roles),
            $currentRoles,
            $this->otherOwners($tenantId, (string) $target->id),
        );

        $permissions = RolePermission::query()->withoutGlobalScopes()->whereIn('role_id', $roleIds)->pluck('permission')->unique()->values()->all();
        if (app()->bound(SodAssignmentGuard::class)) {
            app(SodAssignmentGuard::class)->review($tenantId, (string) $target->id, (string) $roles->firstOrFail()->key, $permissions);
        }

        DB::transaction(function () use ($target, $tenantId, $roleIds, $companyId): void {
            RoleAssignment::query()->where('user_id', $target->id)->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))->delete();
            foreach (array_unique($roleIds) as $roleId) {
                RoleAssignment::create(['tenant_id' => $tenantId, 'user_id' => $target->id, 'role_id' => $roleId, 'company_id' => $companyId]);
            }
            event(new RoleAssigned($tenantId, (string) $target->id, implode(',', $roleIds), (string) auth()->id()));
            $this->resolver->bump($tenantId);
        });
    }

    private function otherOwners(string $tenantId, string $exceptUserId): int
    {
        return (int) DB::table('tenant_user')->where('tenant_id', $tenantId)->where('role', 'owner')->where('user_id', '!=', $exceptUserId)->whereNull('deleted_at')->count();
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_system || $role->tenant_id === null) {
            throw new DomainException('System roles cannot be modified.');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertKnown(array $permissions): void
    {
        $known = $this->registry->keys();
        foreach ($permissions as $permission) {
            if (! in_array($permission, $known, true)) {
                throw new DomainException("Unknown permission [{$permission}].");
            }
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function writePermissions(Role $role, string $tenantId, array $permissions): void
    {
        foreach (array_unique($permissions) as $permission) {
            RolePermission::create(['tenant_id' => $tenantId, 'role_id' => $role->id, 'permission' => $permission]);
        }
    }

    private function uniqueKey(string $tenantId, string $base): string
    {
        $key = $base === '' ? 'role' : $base;
        $candidate = $key;
        $i = 2;
        while (Role::query()->withoutGlobalScopes()->withTrashed()->where('key', $candidate)->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists()) {
            $candidate = $key.'_'.$i++;
        }

        return $candidate;
    }
}
