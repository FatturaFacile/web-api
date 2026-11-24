<?php

declare(strict_types=1);

namespace Modules\Treasury\Policies;

use App\Models\User;
use Modules\Treasury\Models\BankAccount;

final class BankAccountPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, BankAccount $bankAccount): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, BankAccount $bankAccount): bool
    {
        return true;
    }

    public function delete(?User $user, BankAccount $bankAccount): bool
    {
        return true;
    }
}
