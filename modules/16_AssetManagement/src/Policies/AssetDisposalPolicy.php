<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use Modules\AssetManagement\Models\AssetDisposal;

final class AssetDisposalPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, AssetDisposal $disposal): bool
    {
        return $user !== null && ($user->current_tenant_id === null || (string) $user->current_tenant_id === (string) $disposal->tenant_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }
}
