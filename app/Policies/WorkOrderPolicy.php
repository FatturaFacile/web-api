<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToSameTenant($user, $workOrder);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        if (! $this->belongsToSameTenant($user, $workOrder)) {
            return false;
        }

        return $workOrder->status !== 'invoiced';
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        if (! $this->belongsToSameTenant($user, $workOrder)) {
            return false;
        }

        return ! in_array($workOrder->status, ['invoiced', 'signed'], true);
    }

    public function download(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToSameTenant($user, $workOrder);
    }

    private function belongsToSameTenant(User $user, WorkOrder $workOrder): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $workOrder->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $workOrder->tenant_id)->exists();
    }
}
