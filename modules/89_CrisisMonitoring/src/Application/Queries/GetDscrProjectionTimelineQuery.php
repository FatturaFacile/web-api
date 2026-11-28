<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Queries;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The monthly DSCR timeline over six or twelve months, in the treasury (approach 1) or the operating (approach 2) method.
 */
final readonly class GetDscrProjectionTimelineQuery
{
    public function __construct(
        public Tenant $tenant,
        public int $horizon = 12,
        public string $approach = 'treasury',
        public ?CarbonImmutable $asOf = null,
    ) {}
}
