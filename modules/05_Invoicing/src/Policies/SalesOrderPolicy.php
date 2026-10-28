<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use Modules\Invoicing\Models\SalesOrder;

final class SalesOrderPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SalesOrder $order): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, SalesOrder $order): bool
    {
        return true;
    }

    public function delete(?User $user, SalesOrder $order): bool
    {
        return true;
    }
}
