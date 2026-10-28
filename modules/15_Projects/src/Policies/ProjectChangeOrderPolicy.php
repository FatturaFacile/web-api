<?php

declare(strict_types=1);

namespace Modules\Projects\Policies;

use App\Models\User;
use Modules\Projects\Models\ProjectChangeOrder;

final class ProjectChangeOrderPolicy
{
    public function view(User $user, ProjectChangeOrder $order): bool
    {
        return true;
    }

    public function update(User $user, ProjectChangeOrder $order): bool
    {
        return $order->status === 'draft';
    }
}
