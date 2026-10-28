<?php

declare(strict_types=1);

namespace Modules\Procurement\Policies;

use App\Models\User;
use Modules\Procurement\Models\Supplier;

final class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return (string) $user->tenant_id === (string) $supplier->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return (string) $user->tenant_id === (string) $supplier->tenant_id
            && ! $supplier->isLockedByWorkflow();
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return (string) $user->tenant_id === (string) $supplier->tenant_id
            && ! $supplier->isLockedByWorkflow();
    }
}
