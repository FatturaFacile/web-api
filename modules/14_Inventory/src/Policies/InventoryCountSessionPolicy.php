<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\InventoryCountSession;

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

    public function reconcile(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && $session->status === 'in_progress'
            && ! $session->isLockedByWorkflow();
    }

    public function cancel(User $user, InventoryCountSession $session): bool
    {
        return (string) $user->tenant_id === (string) $session->tenant_id
            && in_array($session->status, ['draft', 'in_progress'], true)
            && ! $session->isLockedByWorkflow();
    }
}
