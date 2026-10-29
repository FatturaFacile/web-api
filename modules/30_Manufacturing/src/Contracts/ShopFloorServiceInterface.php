<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\RecordShopFloorExecutionDTO;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

interface ShopFloorServiceInterface
{
    public function recordExecution(RecordShopFloorExecutionDTO $dto): MfgShopFloorExecution;

    public function completeExecution(string $executionId, float $goodUnits, float $scrapUnits): MfgShopFloorExecution;

    public function recordDowntime(string $executionId, float $downtimeMinutes, string $reason): MfgShopFloorExecution;
}
