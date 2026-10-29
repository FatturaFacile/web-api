<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

final class MaintenanceWorkOrderPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, MaintenanceWorkOrder $workOrder): bool
    {
        return $user !== null && ($user->current_tenant_id === null || (string) $user->current_tenant_id === (string) $workOrder->tenant_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, MaintenanceWorkOrder $workOrder): bool
    {
        return $this->view($user, $workOrder) && ! $workOrder->is_locked;
    }

    public function complete(?User $user, MaintenanceWorkOrder $workOrder): bool
    {
        return $this->view($user, $workOrder) && ! $workOrder->is_locked;
    }

    public function delete(?User $user, MaintenanceWorkOrder $workOrder): bool
    {
        return $this->view($user, $workOrder) && ! $workOrder->is_locked;
    }
}
