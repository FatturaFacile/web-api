<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\StockItem;

final class StockItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockItem $item): bool
    {
        return (string) $user->tenant_id === (string) $item->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockItem $item): bool
    {
        return (string) $user->tenant_id === (string) $item->tenant_id;
    }

    public function delete(User $user, StockItem $item): bool
    {
        return (string) $user->tenant_id === (string) $item->tenant_id;
    }
}
