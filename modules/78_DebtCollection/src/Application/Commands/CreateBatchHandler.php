<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Services\BatchService;

final class CreateBatchHandler
{
    public function __construct(
        private BatchService $batches,
    ) {}

    public function handle(CreateBatchCommand $command): Batch
    {
        return $this->batches->create($command->tenantId, $command->noticeIds, $command->channel, $command->actorId);
    }
}
