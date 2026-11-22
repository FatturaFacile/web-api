<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * Physical counts: every writer of the inventory module counts; approving the differences (which posts stock and
 * ledger entries) and cancelling a session are manager decisions (InventoryManagers).
 */
final class InventoryCountSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function recordCount(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && in_array($session->status, ['in_progress', 'draft'], true)
            && ! $session->isLockedByWorkflow();
    }

    /**
     * Sending lines back for a recount: whoever may count.
     */
    public function recount(User $user, InventoryCountSession $session): bool
    {
        return $this->recordCount($user, $session);
    }

    public function reconcile(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && $session->status === 'in_progress'
            && ! $session->isLockedByWorkflow()
            && InventoryManagers::includes($user);
    }

    /**
     * One approval level of the differences (multi-level mode): level 1 is the warehouse manager, level 2 the finance
     * approver. Segregation of duties is checked by the service.
     */
    public function approveLevel(User $user, InventoryCountSession $session, int $level): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && $session->status === 'pending_approval'
            && ($level === 1 ? InventoryManagers::includes($user) : InventoryManagers::isFinanceApprover($user));
    }

    /**
     * Seeing the system quantities and variances of a count: a manager or the finance approver. Counters never do.
     */
    public function viewVariance(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && (InventoryManagers::includes($user) || InventoryManagers::isFinanceApprover($user));
    }

    /**
     * Freezing, releasing the freeze and assigning counters: manager decisions on an open session.
     */
    public function administer(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && in_array($session->status, ['in_progress', 'pending_approval'], true)
            && InventoryManagers::includes($user);
    }

    public function cancel(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && in_array($session->status, ['draft', 'in_progress'], true)
            && ! $session->isLockedByWorkflow()
            && InventoryManagers::includes($user);
    }
}
