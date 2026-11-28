<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\Inspection;
use Modules\Beekeeping\Services\InspectionService;

final class RecordHiveInspectionHandler
{
    public function __construct(private readonly InspectionService $service) {}

    public function handle(RecordHiveInspectionCommand $command): Inspection
    {
        return $this->service->record($command->hive, $command->data, $command->operatorId);
    }
}
