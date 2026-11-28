<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Commands;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Computes and stores the rolling DSCR projection and the CCII / Art. 725 CO assessments of a company: on demand (`manual`) or from the
 * daily schedule (`scheduled`). Re-running the same day replaces the day's snapshot.
 */
final readonly class ComputeRollingDscrAndCrisisIndicatorsCommand
{
    public function __construct(
        public Tenant $tenant,
        public ?CarbonImmutable $asOf = null,
        public string $trigger = 'manual',
    ) {}
}
