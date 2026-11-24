<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use Modules\GeneralLedger\Models\FiscalPeriod;

final class FiscalPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FiscalPeriod $period): bool
    {
        return $user->tenant_id === $period->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FiscalPeriod $period): bool
    {
        return $user->tenant_id === $period->tenant_id;
    }

    public function delete(User $user, FiscalPeriod $period): bool
    {
        return $user->tenant_id === $period->tenant_id && $period->status !== 'closed';
    }
}
