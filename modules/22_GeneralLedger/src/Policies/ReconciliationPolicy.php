<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use Modules\GeneralLedger\Models\ReconciliationRun;

final class ReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReconciliationRun $run): bool
    {
        return $user->tenant_id === $run->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
