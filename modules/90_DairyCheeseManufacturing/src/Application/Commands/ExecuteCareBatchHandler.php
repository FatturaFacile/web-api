<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Services\CareService;

final class ExecuteCareBatchHandler
{
    public function __construct(private readonly CareService $care) {}

    public function handle(ExecuteCareBatchCommand $command): AgingCareLog
    {
        return $this->care->executeBatch($command->careType, $command->scope, $command->targetId, $command->performedBy, $command->notes);
    }
}
