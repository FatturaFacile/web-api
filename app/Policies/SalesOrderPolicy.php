<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $this->belongsToSameTenant($user, $order);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SalesOrder $order): bool
    {
        if (! $this->belongsToSameTenant($user, $order)) {
            return false;
        }

        return $order->status !== 'cancelled';
    }

    public function delete(User $user, SalesOrder $order): bool
    {
        if (! $this->belongsToSameTenant($user, $order)) {
            return false;
        }

        return ! in_array($order->status, ['fulfilled', 'invoiced'], true);
    }

    public function download(User $user, SalesOrder $order): bool
    {
        return $this->belongsToSameTenant($user, $order);
    }

    private function belongsToSameTenant(User $user, SalesOrder $order): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $order->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $order->tenant_id)->exists();
    }
}
