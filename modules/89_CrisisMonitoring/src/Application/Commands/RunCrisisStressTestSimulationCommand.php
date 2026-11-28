<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Commands;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Domain\Stress\StressShock;

/**
 * Runs a what-if shock (revenue drop, collection delay, rate hike, extraordinary outflow) against the live figures of the company. The
 * simulation never writes an accounting record or a snapshot.
 */
final readonly class RunCrisisStressTestSimulationCommand
{
    public function __construct(
        public Tenant $tenant,
        public StressShock $shock,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
