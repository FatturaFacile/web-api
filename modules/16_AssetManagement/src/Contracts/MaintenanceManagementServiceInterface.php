<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

interface MaintenanceManagementServiceInterface
{
    public function createMaintenancePlan(array $data, ?string $actorUserId = null): MaintenancePlan;

    public function createWorkOrder(CreateMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder;

    public function completeWorkOrder(CompleteMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder;
}
