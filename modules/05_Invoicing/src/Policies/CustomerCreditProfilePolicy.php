<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use Modules\Invoicing\Models\CustomerCreditProfile;

final class CustomerCreditProfilePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }

    public function delete(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }
}
