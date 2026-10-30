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
        return $this->checkTenant($user, $order);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function submitApproval(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && $order->status === 'draft'
            && ! $order->isLockedByWorkflow();
    }

    public function approve(User $user, PurchaseOrder $order): bool
    {
        if ((string) $order->created_by === (string) $user->id) {
            return false;
        }

        if (! in_array($order->status, ['pending_approval', 'submitted'], true)) {
            return false;
        }

        return $this->checkTenant($user, $order);
    }

    public function reject(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && $order->status === 'pending_approval';
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && ! in_array($order->status, ['fully_received', 'matched', 'cancelled'], true);
    }

    public function close(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && in_array($order->status, ['approved', 'partially_received', 'received'], true);
    }

    public function reopen(User $user, PurchaseOrder $order): bool
    {
        return $this->checkTenant($user, $order)
            && $order->status === 'closed';
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        if (! $this->checkTenant($user, $order)) {
            return false;
        }

        // Segregation of Duties: Creator of order cannot sign off as receiver
        if ($order->created_by !== null && (string) $order->created_by === (string) $user->id) {
            return false;
        }

        return in_array($order->status, ['approved', 'partially_received'], true);
    }

    private function checkTenant(User $user, PurchaseOrder $order): bool
    {
        if (! empty($user->tenant_id) && ! empty($order->tenant_id) && (string) $user->tenant_id !== (string) $order->tenant_id) {
            return false;
        }

        if (! empty($order->tenant_id) && $user->tenants()->exists() && ! $user->belongsToTenant((string) $order->tenant_id)) {
            return false;
        }

        return true;
    }
}
