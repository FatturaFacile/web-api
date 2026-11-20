<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Commands;

use Modules\Forecasting\Application\DTOs\WhatIfScenarioDto;

/**
 * Project the cash balance of the tenant day by day as P10/P50/P90 bands.
 */
final readonly class RunAiLiquiditySimulationCommand
{
    public function __construct(
        public string $tenantId,
        public int $horizonDays = 91,
        public ?WhatIfScenarioDto $whatIf = null,
        /** Keep the run for the history; a preview (the what-if sliders) is not persisted. */
        public bool $persist = true,
    ) {}
}
