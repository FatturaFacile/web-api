<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Queries\CalculateRequiredRangruecktrittAmountQuery;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;

/**
 * The subordination amount still needed to cure the over-indebtedness, for the horizon, the rate and the valuation basis asked.
 */
final class CalculateRequiredRangruecktrittAmountHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CalculateRequiredRangruecktrittAmountQuery $query): array
    {
        $inputs = $this->engine->load($query->tenant, $query->asOf);

        return $this->engine->requiredRangruecktritt($inputs, max(1, min(60, $query->horizonMonths)), $query->interestRatePercent, $query->basis);
    }
}
