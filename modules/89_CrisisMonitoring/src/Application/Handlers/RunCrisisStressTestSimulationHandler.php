<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Commands\RunCrisisStressTestSimulationCommand;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;
use Modules\CrisisMonitoring\Application\Services\StressTestSummary;

/**
 * The stress test: the baseline and the shocked picture of the company computed from the same live inputs, side by side. Nothing is saved.
 */
final class RunCrisisStressTestSimulationHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array{shock: array<string, int|string>, baseline: array<string, mixed>, stressed: array<string, mixed>, deltas: array<string, string|null>}
     */
    public function handle(RunCrisisStressTestSimulationCommand $command): array
    {
        $inputs = $this->engine->load($command->tenant, $command->asOf);
        $baseline = StressTestSummary::lite($this->engine->evaluate($inputs));
        $stressed = StressTestSummary::lite($this->engine->evaluate($inputs, $command->shock));

        return [
            'shock' => $command->shock->toArray(),
            'baseline' => $baseline,
            'stressed' => $stressed,
            'deltas' => StressTestSummary::deltas($baseline, $stressed),
        ];
    }
}
