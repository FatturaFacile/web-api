<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\StockMovement;

final class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return (string) $user->tenant_id === (string) $movement->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        // Movements are append-only immutable ledger records
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        // Movements cannot be deleted
        return false;
    }
}
