<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Services\HoneyService;

final class CreateExtractionBatchHandler
{
    public function __construct(private readonly HoneyService $service) {}

    public function handle(CreateExtractionBatchCommand $command): ExtractionBatch
    {
        return $this->service->createBatch($command->data);
    }
}
