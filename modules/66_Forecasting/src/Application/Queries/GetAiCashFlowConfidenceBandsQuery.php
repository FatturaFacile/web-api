<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Queries;

/**
 * Chart series of a liquidity run: the latest one of the tenant unless a run is named.
 */
final readonly class GetAiCashFlowConfidenceBandsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $runId = null,
        public ?int $horizonDays = null,
    ) {}
}
