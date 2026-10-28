<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\InventoryAdjustment;

final class InventoryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InventoryAdjustment $adjustment): bool
    {
        return (string) $user->tenant_id === (string) $adjustment->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InventoryAdjustment $adjustment): bool
    {
        return (string) $user->tenant_id === (string) $adjustment->tenant_id
            && $adjustment->status === 'draft'
            && ! $adjustment->isLockedByWorkflow();
    }

    public function approve(User $user, InventoryAdjustment $adjustment): bool
    {
        return (string) $user->tenant_id === (string) $adjustment->tenant_id
            && $adjustment->status === 'pending_approval'
            && (string) $adjustment->created_by !== (string) $user->id; // Segregation of Duties
    }

    public function reject(User $user, InventoryAdjustment $adjustment): bool
    {
        return (string) $user->tenant_id === (string) $adjustment->tenant_id
            && $adjustment->status === 'pending_approval';
    }

    public function delete(User $user, InventoryAdjustment $adjustment): bool
    {
        return (string) $user->tenant_id === (string) $adjustment->tenant_id
            && $adjustment->status === 'draft'
            && ! $adjustment->isLockedByWorkflow();
    }
}
