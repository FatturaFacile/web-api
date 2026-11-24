<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use Modules\GeneralLedger\Models\FiscalYear;

final class FiscalYearPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FiscalYear $year): bool
    {
        return $user->tenant_id === $year->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FiscalYear $year): bool
    {
        return $user->tenant_id === $year->tenant_id;
    }

    public function delete(User $user, FiscalYear $year): bool
    {
        return $user->tenant_id === $year->tenant_id && $year->status !== 'closed';
    }
}
