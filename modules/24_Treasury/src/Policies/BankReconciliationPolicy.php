<?php

declare(strict_types=1);

namespace Modules\Treasury\Policies;

use App\Models\User;
use Modules\Treasury\Models\BankReconciliation;

final class BankReconciliationPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, BankReconciliation $recon): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function approve(?User $user, BankReconciliation $recon): bool
    {
        return true;
    }
}
