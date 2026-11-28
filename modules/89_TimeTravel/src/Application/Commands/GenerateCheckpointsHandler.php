<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Commands;

use Modules\TimeTravel\Domain\ValueObjects\MaterializedStateCheckpoint;
use Modules\TimeTravel\Services\CheckpointService;

final readonly class GenerateCheckpointsHandler
{
    public function __construct(private CheckpointService $checkpoints) {}

    /**
     * @return list<MaterializedStateCheckpoint>
     */
    public function handle(GenerateCheckpointsCommand $command): array
    {
        return $this->checkpoints->generateAll($command->tenantId, $command->aggregateType !== null ? [$command->aggregateType] : null);
    }
}
