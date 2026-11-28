<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Queries;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The CNC practical test of the D.M. 21 March 2023: debt to be restructured against the annual free cash flow, with the checklist.
 */
final readonly class GetCncPracticalTestQuery
{
    public function __construct(
        public Tenant $tenant,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
