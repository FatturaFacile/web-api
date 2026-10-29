<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

final class MfgShopFloorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MfgShopFloorExecution $execution): bool
    {
        return true;
    }

    public function execute(User $user): bool
    {
        return true;
    }
}
