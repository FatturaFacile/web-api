<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Services\VanWarehouseManagementService;

final readonly class CreateVanWarehouseHandler
{
    public function __construct(private VanWarehouseManagementService $service) {}

    public function handle(CreateVanWarehouseCommand $command): VanWarehouse
    {
        return $this->service->create($command->tenantId, $command->payload);
    }
}
