<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Services\VatBatchService;

final class CreateVatBatchHandler
{
    public function __construct(private readonly VatBatchService $batches) {}

    public function handle(CreateVatBatchCommand $command): CheeseVatBatch
    {
        return $this->batches->create($command->data, $command->userId);
    }
}
