<?php

declare(strict_types=1);

namespace Modules\Procurement\Policies;

use App\Models\User;
use Modules\Procurement\Models\SupplierContract;

final class SupplierContractPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SupplierContract $contract): bool
    {
        return (string) $user->tenant_id === (string) $contract->tenant_id;
    }

    public function update(User $user, SupplierContract $contract): bool
    {
        return (string) $user->tenant_id === (string) $contract->tenant_id
            && ! $contract->isLockedByWorkflow();
    }
}
