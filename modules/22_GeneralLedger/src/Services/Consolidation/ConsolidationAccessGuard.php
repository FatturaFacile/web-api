<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\User;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tenant safety of the consolidation. The consolidation reads the ledgers of several entities (tenants) at once, so the
 * request-level check on the active tenant (module.access:ledger) is not enough: the user must also be able to read the
 * ledger of every member entity through the same rule of the access model (membership of the tenant and a role, or a
 * grant, that opens the `ledger` module for reading, config/permissions.php).
 *
 * The rule is all or nothing. A consolidation of the entities a user happens to see would be a wrong figure, so when
 * any member is out of reach the request is refused as a whole and the answer does not say which entity it was.
 * The same check protects the stored results of a run, which hold figures of every member. A request authenticated
 * with an API key is never enough: a key is bound to one tenant.
 */
final readonly class ConsolidationAccessGuard
{
    public function __construct(private ModuleAccessGate $gate) {}

    /**
     * @throws AccessDeniedHttpException when the user cannot read the ledger of every member of the group
     */
    public function assertCanReadGroup(?User $user, ConsolidationGroup $group): void
    {
        $tenantIds = ConsolidationMember::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('tenant_id', $group->tenant_id)
            ->pluck('member_tenant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->push((string) $group->tenant_id)
            ->unique()
            ->all();

        $this->assertCanReadEntities($user, $tenantIds);
    }

    /**
     * @param  list<string>  $tenantIds
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanReadEntities(?User $user, array $tenantIds): void
    {
        foreach ($tenantIds as $tenantId) {
            if (! $this->canReadEntity($user, $tenantId)) {
                throw new AccessDeniedHttpException('Your access does not cover every entity of the consolidation group.');
            }
        }
    }

    public function canReadEntity(?User $user, string $tenantId): bool
    {
        return $user !== null && $this->gate->allows($user, $tenantId, 'ledger', 'read');
    }
}
