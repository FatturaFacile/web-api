<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Services\BatchService;

final class RetryBatchHandler
{
    public function __construct(
        private BatchService $batches,
    ) {}

    public function handle(RetryBatchCommand $command): Batch
    {
        return $this->batches->retry(Batch::query()->findOrFail($command->batchId), $command->tenantId, $command->actorId);
    }
}
