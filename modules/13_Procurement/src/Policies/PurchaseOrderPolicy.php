<?php

declare(strict_types=1);

namespace Modules\Procurement\Policies;

use App\Models\User;
use Modules\Procurement\Models\PurchaseOrder;

final class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function submitApproval(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function approve(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'pending_approval'
            && (string) $order->created_by !== (string) $user->id; // Segregation of duties
    }

    public function reject(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'pending_approval';
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && ! in_array($order->status, ['fully_received', 'matched', 'cancelled'], true);
    }

    public function close(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && in_array($order->status, ['approved', 'partially_received', 'received'], true);
    }

    public function reopen(User $user, PurchaseOrder $order): bool
    {
        return (string) $user->tenant_id === (string) $order->tenant_id
            && $order->status === 'closed';
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        if ((string) $user->tenant_id !== (string) $order->tenant_id) {
            return false;
        }

        // Segregation of Duties: Creator of order cannot sign off as receiver
        if ($order->created_by !== null && (string) $order->created_by === (string) $user->id) {
            return false;
        }

        return in_array($order->status, ['approved', 'partially_received'], true);
    }
}
