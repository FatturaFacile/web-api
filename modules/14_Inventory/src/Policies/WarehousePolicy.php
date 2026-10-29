<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\Warehouse;

final class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return (string) $user->tenant_id === (string) $warehouse->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return (string) $user->tenant_id === (string) $warehouse->tenant_id
            && ! $warehouse->isLockedByWorkflow();
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return (string) $user->tenant_id === (string) $warehouse->tenant_id
            && ! $warehouse->isLockedByWorkflow();
    }
}
