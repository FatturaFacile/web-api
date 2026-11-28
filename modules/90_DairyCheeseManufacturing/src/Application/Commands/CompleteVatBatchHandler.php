<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Services\VatBatchService;

final class CompleteVatBatchHandler
{
    public function __construct(private readonly VatBatchService $batches) {}

    public function handle(CompleteVatBatchCommand $command): CheeseVatBatch
    {
        return $this->batches->complete(CheeseVatBatch::query()->findOrFail($command->batchId), $command->data);
    }
}
