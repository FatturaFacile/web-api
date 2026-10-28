<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use Modules\GeneralLedger\Models\ChartOfAccount;

final class ChartOfAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        return $user->tenant_id === $account->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ChartOfAccount $account): bool
    {
        return $user->tenant_id === $account->tenant_id;
    }

    public function delete(User $user, ChartOfAccount $account): bool
    {
        return $user->tenant_id === $account->tenant_id;
    }
}
