<?php

declare(strict_types=1);

namespace Modules\Procurement\Policies;

use App\Models\User;
use Modules\Procurement\Models\PurchaseRequisition;

final class PurchaseRequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseRequisition $requisition): bool
    {
        return (string) $user->tenant_id === (string) $requisition->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PurchaseRequisition $requisition): bool
    {
        return (string) $user->tenant_id === (string) $requisition->tenant_id
            && $requisition->status === 'draft'
            && ! $requisition->isLockedByWorkflow();
    }

    public function delete(User $user, PurchaseRequisition $requisition): bool
    {
        return (string) $user->tenant_id === (string) $requisition->tenant_id
            && $requisition->status === 'draft'
            && ! $requisition->isLockedByWorkflow();
    }

    public function submitApproval(User $user, PurchaseRequisition $requisition): bool
    {
        return (string) $user->tenant_id === (string) $requisition->tenant_id
            && $requisition->status === 'draft'
            && ! $requisition->isLockedByWorkflow();
    }
}
