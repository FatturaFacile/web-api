<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Platform\Identity\Domain\ValueObjects\AbacCondition;
use Modules\Platform\Identity\Domain\ValueObjects\AbacRule;
use Modules\Platform\Identity\Infrastructure\Models\AbacRuleRecord;
use Modules\Platform\Identity\Infrastructure\Models\FieldPolicy;
use Modules\Platform\Identity\Infrastructure\Models\Role;
use Modules\Platform\Identity\Infrastructure\Models\RoleAssignment;
use Modules\Platform\Identity\Infrastructure\Models\RolePermission;

/**
 * Union of the permissions, ABAC rules and field policies a user holds in a tenant and company. The result is cached
 * under a per-tenant version: bump() after any role or assignment change invalidates every user of the tenant.
 *
 * Legacy memberships keep working: the pivot role `owner` or `admin` grants everything, the pivot permission list is
 * added as is, and the pivot role is matched with the system role of the same key.
 */
final class EffectivePermissionResolver
{
    /**
     * @return array{permissions: list<string>, abac_rules: list<array{permission: string, effect: string, conditions: list<array<string, mixed>>}>, field_policies: list<array{resource: string, field: string, visibility: string, kind: string}>}
     */
    public function snapshot(User $user, string $tenantId, ?string $companyId = null): array
    {
        $key = sprintf('iam:%s:%s:%s:%s', $tenantId, $this->version($tenantId), $user->id, $companyId ?? 'all');

        /** @var array{permissions: list<string>, abac_rules: list<array{permission: string, effect: string, conditions: list<array<string, mixed>>}>, field_policies: list<array{resource: string, field: string, visibility: string, kind: string}>} */
        return Cache::remember($key, 300, fn (): array => $this->build($user, $tenantId, $companyId));
    }

    /**
     * @return list<string>
     */
    public function permissions(User $user, string $tenantId, ?string $companyId = null): array
    {
        return $this->snapshot($user, $tenantId, $companyId)['permissions'];
    }

    /**
     * @return list<AbacRule>
     */
    public function abacRules(User $user, string $tenantId, ?string $companyId = null): array
    {
        return array_map(static function (array $r): AbacRule {
            $conditions = array_map(
                static fn (array $c): AbacCondition => new AbacCondition((string) $c['attribute'], (string) $c['operator'], $c['value'] ?? null),
                $r['conditions'],
            );

            return new AbacRule($r['permission'], $r['effect'], $conditions);
        }, $this->snapshot($user, $tenantId, $companyId)['abac_rules']);
    }

    public function bump(string $tenantId): void
    {
        Cache::forever("iam:version:{$tenantId}", ((int) Cache::get("iam:version:{$tenantId}", 1)) + 1);
    }

    private function version(string $tenantId): int
    {
        return (int) Cache::get("iam:version:{$tenantId}", 1);
    }

    /**
     * @return array{permissions: list<string>, abac_rules: list<array{permission: string, effect: string, conditions: list<array<string, mixed>>}>, field_policies: list<array{resource: string, field: string, visibility: string, kind: string}>}
     */
    private function build(User $user, string $tenantId, ?string $companyId): array
    {
        $permissions = [];
        $rules = [];
        $policies = [];

        $pivot = $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
        $roleIds = [];

        if ($pivot !== null) {
            if (in_array($pivot->role, ['owner', 'admin'], true)) {
                $permissions[] = '*';
            }

            $legacy = is_string($pivot->permissions) ? json_decode($pivot->permissions, true) : $pivot->permissions;
            if (is_array($legacy)) {
                array_push($permissions, ...array_values(array_filter($legacy, 'is_string')));
            }

            $system = Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', (string) $pivot->role)->value('id');
            if (is_string($system)) {
                $roleIds[] = $system;
            }
        }

        $assigned = RoleAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('company_id')->when($companyId !== null, fn ($q) => $q->orWhere('company_id', $companyId)))
            ->pluck('role_id')->all();
        $roleIds = array_values(array_unique([...$roleIds, ...$assigned]));

        if ($roleIds !== []) {
            array_push($permissions, ...RolePermission::query()->withoutGlobalScopes()->whereIn('role_id', $roleIds)->pluck('permission')->all());

            foreach (AbacRuleRecord::query()->withoutGlobalScopes()->whereIn('role_id', $roleIds)->get() as $rule) {
                $rules[] = ['permission' => $rule->permission, 'effect' => $rule->effect, 'conditions' => $rule->conditions];
            }

            foreach (FieldPolicy::query()->withoutGlobalScopes()->whereIn('role_id', $roleIds)->get() as $policy) {
                $policies[] = ['resource' => $policy->resource, 'field' => $policy->field, 'visibility' => $policy->visibility->value, 'kind' => $policy->kind];
            }
        }

        return ['permissions' => array_values(array_unique($permissions)), 'abac_rules' => $rules, 'field_policies' => $policies];
    }
}
