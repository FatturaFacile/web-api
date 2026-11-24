<?php

declare(strict_types=1);

namespace Modules\CRM\Policies;

use App\Models\User;
use Modules\CRM\Models\Activity;

final class ActivityPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Activity $activity): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, Activity $activity): bool
    {
        return true;
    }

    public function delete(?User $user, Activity $activity): bool
    {
        return true;
    }
}
