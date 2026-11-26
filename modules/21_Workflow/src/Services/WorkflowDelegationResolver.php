<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Modules\Workflow\Models\WorkflowDelegation;

/**
 * Follows delegation chains inside one tenant. A delegates to B and B to C: an approval of A is stood in for by C, up to
 * `config('workflow.delegation_max_depth')` (3) hops. A delegation that would close a loop is refused when it is saved.
 */
final class WorkflowDelegationResolver
{
    /**
     * The user who finally stands in for `$userId` now, within the scope and amount cap of every delegation of the chain;
     * `$userId` itself when nobody does.
     */
    public function resolve(string $userId, string $tenantId, string $approvableType, ?float $amount = null): string
    {
        $current = $userId;
        $visited = [$userId];

        for ($depth = 0; $depth < $this->maxDepth(); $depth++) {
            $delegation = $this->activeFrom($current, $tenantId)
                ->first(static fn (WorkflowDelegation $candidate): bool => $candidate->coversApprovable($approvableType, $amount));

            if ($delegation === null || in_array((string) $delegation->delegate_user_id, $visited, true)) {
                break;
            }

            $current = (string) $delegation->delegate_user_id;
            $visited[] = $current;
        }

        return $current;
    }

    /**
     * Whether a new delegation of `$delegatorId` to `$delegateId` would close a loop, i.e. the delegate (directly or through
     * others, within the depth limit) already delegates back to the delegator. Delegations still to start count too.
     */
    public function wouldCreateCycle(string $delegatorId, string $delegateId, string $tenantId): bool
    {
        $frontier = [$delegateId];
        $seen = [$delegateId];

        for ($depth = 0; $depth < $this->maxDepth() + 1; $depth++) {
            $next = [];

            foreach ($frontier as $user) {
                foreach ($this->openFrom($user, $tenantId) as $delegation) {
                    $target = (string) $delegation->delegate_user_id;

                    if ($target === $delegatorId) {
                        return true;
                    }

                    if (! in_array($target, $seen, true)) {
                        $seen[] = $target;
                        $next[] = $target;
                    }
                }
            }

            if ($next === []) {
                return false;
            }

            $frontier = $next;
        }

        return false;
    }

    /**
     * Delegations of the user in force at this moment.
     *
     * @return Collection<int, WorkflowDelegation>
     */
    private function activeFrom(string $userId, string $tenantId): Collection
    {
        return WorkflowDelegation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('delegator_user_id', $userId)
            ->where('is_active', true)
            ->where('starts_at', '<=', Date::now())
            ->where('ends_at', '>=', Date::now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Delegations of the user that are not over yet (in force or still to start).
     *
     * @return Collection<int, WorkflowDelegation>
     */
    private function openFrom(string $userId, string $tenantId): Collection
    {
        return WorkflowDelegation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('delegator_user_id', $userId)
            ->where('is_active', true)
            ->where('ends_at', '>=', Date::now())
            ->get();
    }

    private function maxDepth(): int
    {
        return max(1, (int) config('workflow.delegation_max_depth', 3));
    }
}
