<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Services\WashBatchService;

final class CreateWashBatchHandler
{
    public function __construct(private readonly WashBatchService $batches) {}

    public function handle(CreateWashBatchCommand $command): WashBatch
    {
        return $this->batches->create($command->tenantId, $command->data, $command->userId);
    }
}
