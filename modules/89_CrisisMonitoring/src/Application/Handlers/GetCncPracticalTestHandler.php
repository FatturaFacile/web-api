<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Queries\GetCncPracticalTestQuery;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;

/**
 * The CNC practical test of the company.
 */
final class GetCncPracticalTestHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCncPracticalTestQuery $query): array
    {
        return $this->engine->evaluate($this->engine->load($query->tenant, $query->asOf))['ccii']['cnc'];
    }
}
