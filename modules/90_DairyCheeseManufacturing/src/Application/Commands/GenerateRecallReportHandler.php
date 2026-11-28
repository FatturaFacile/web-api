<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Services\TraceabilityService;

final class GenerateRecallReportHandler
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GenerateRecallReportCommand $command): array
    {
        return $this->traceability->recall($command->type, $command->id, $command->serial, $command->reason, $command->apply, $command->userId);
    }
}
