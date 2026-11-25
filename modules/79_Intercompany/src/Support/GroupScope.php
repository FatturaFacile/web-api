<?php

declare(strict_types=1);

namespace Modules\Intercompany\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationAccessGuard;
use Modules\Intercompany\Exceptions\IntercompanyException;

/**
 * Resolves the entities of a consolidation group and checks that the user can read every one of them, the rule of the
 * consolidation module (ConsolidationAccessGuard). The group is read through the tenant scope: only the groups of the
 * active (parent) tenant exist.
 */
final readonly class GroupScope
{
    public function __construct(private ConsolidationAccessGuard $guard) {}

    public function group(string $groupId): ConsolidationGroup
    {
        return ConsolidationGroup::query()->findOrFail($groupId);
    }

    public function run(string $runId): ConsolidationRun
    {
        return ConsolidationRun::query()->findOrFail($runId);
    }

    public function groupOfRun(ConsolidationRun $run): ConsolidationGroup
    {
        return ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->findOrFail($run->group_id);
    }

    public function assertReadable(?User $user, ConsolidationGroup $group): void
    {
        $this->guard->assertCanReadGroup($user, $group);
    }

    /**
     * Tenant ids of the group: the parent first, then the members.
     *
     * @return list<string>
     */
    public function entityIds(ConsolidationGroup $group): array
    {
        $members = ConsolidationMember::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('tenant_id', $group->tenant_id)
            ->pluck('member_tenant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([(string) $group->tenant_id, ...$members]));
    }

    /**
     * Runs the callback in the books of an entity (the tenant scope and the tenant stamp of new rows follow it), then
     * restores the previous context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $tenantId, callable $callback): mixed
    {
        return app(TenantContext::class)->runWithTenant(Tenant::withoutGlobalScopes()->findOrFail($tenantId), $callback);
    }

    /**
     * @param  list<string>  $tenantIds
     *
     * @throws IntercompanyException when a tenant is not an entity of the group
     */
    public function assertEntities(ConsolidationGroup $group, array $tenantIds): void
    {
        $entities = $this->entityIds($group);

        foreach ($tenantIds as $tenantId) {
            if (! in_array($tenantId, $entities, true)) {
                throw new IntercompanyException('The entity does not belong to the consolidation group.', 'entity_not_in_group');
            }
        }
    }

    /**
     * @param  list<string>  $tenantIds
     * @return array<string, string> tenant id => name
     */
    public function names(array $tenantIds): array
    {
        return Tenant::withoutGlobalScopes()->whereIn('id', $tenantIds)->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();
    }
}
