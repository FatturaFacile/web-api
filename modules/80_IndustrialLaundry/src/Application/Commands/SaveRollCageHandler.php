<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Services\RollCageService;

final class SaveRollCageHandler
{
    public function __construct(private readonly RollCageService $cages) {}

    public function handle(SaveRollCageCommand $command): RollCage
    {
        $cage = $command->rollCageId === null ? null : RollCage::query()->findOrFail($command->rollCageId);

        return $this->cages->save($command->tenantId, $command->data, $command->userId, $cage);
    }
}
