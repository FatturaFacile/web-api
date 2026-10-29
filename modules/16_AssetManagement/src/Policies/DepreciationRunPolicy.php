<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use Modules\AssetManagement\Models\DepreciationRun;

final class DepreciationRunPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, DepreciationRun $run): bool
    {
        return $user !== null && ($user->current_tenant_id === null || (string) $user->current_tenant_id === (string) $run->tenant_id);
    }

    public function execute(?User $user): bool
    {
        return $user !== null;
    }
}
