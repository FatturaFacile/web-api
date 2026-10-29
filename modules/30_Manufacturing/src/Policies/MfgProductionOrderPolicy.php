<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgProductionOrder;

final class MfgProductionOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MfgProductionOrder $order): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MfgProductionOrder $order): bool
    {
        return ! $order->is_locked && ! $order->isCompleted();
    }

    public function delete(User $user, MfgProductionOrder $order): bool
    {
        return $order->status === 'planned';
    }

    public function release(User $user, MfgProductionOrder $order): bool
    {
        return in_array($order->status, ['planned', 'scheduled'], true);
    }

    public function complete(User $user, MfgProductionOrder $order): bool
    {
        return $order->status === 'in_progress';
    }

    public function cancel(User $user, MfgProductionOrder $order): bool
    {
        return ! in_array($order->status, ['completed', 'closed'], true);
    }
}
