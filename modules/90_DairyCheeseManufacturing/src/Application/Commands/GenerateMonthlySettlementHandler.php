<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;
use Modules\DairyCheeseManufacturing\Services\SettlementService;

final class GenerateMonthlySettlementHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return list<MonthlySettlement>
     */
    public function handle(GenerateMonthlySettlementCommand $command): array
    {
        return $this->settlements->generate($command->period, $command->gridId, $command->breederIds, $command->userId);
    }
}
