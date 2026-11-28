<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class GenerateMonthlySettlementCommand
{
    /**
     * @param  list<string>|null  $breederIds
     */
    public function __construct(public string $period, public string $gridId, public ?array $breederIds = null, public ?string $userId = null) {}
}
