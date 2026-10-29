<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\WorkOrder;

final class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToTenant($user, (string) $workOrder->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToTenant($user, (string) $workOrder->tenant_id) && ! $workOrder->is_locked;
    }

    public function dispatch(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToTenant($user, (string) $workOrder->tenant_id) && ! $workOrder->is_locked;
    }

    public function complete(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToTenant($user, (string) $workOrder->tenant_id) && ! $workOrder->is_locked;
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $this->belongsToTenant($user, (string) $workOrder->tenant_id) && ! $workOrder->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
