<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Services\WashBatchService;

final class CertifyWashBatchHandler
{
    public function __construct(private readonly WashBatchService $batches) {}

    public function handle(CertifyWashBatchCommand $command): WashBatch
    {
        return $this->batches->certify(WashBatch::query()->findOrFail($command->washBatchId), $command->data, $command->userId);
    }
}
