<?php

declare(strict_types=1);

namespace Modules\CRM\Policies;

use App\Models\User;
use Modules\CRM\Models\Opportunity;

final class OpportunityPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Opportunity $opportunity): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, Opportunity $opportunity): bool
    {
        return true;
    }

    public function delete(?User $user, Opportunity $opportunity): bool
    {
        return true;
    }
}
