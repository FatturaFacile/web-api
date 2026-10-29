<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgQualityInspection;

final class MfgQualityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MfgQualityInspection $inspection): bool
    {
        return true;
    }

    public function inspect(User $user): bool
    {
        return true;
    }
}
