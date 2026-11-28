<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Queries\GetDscrProjectionTimelineQuery;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;

/**
 * The monthly DSCR timeline of the requested horizon and approach.
 */
final class GetDscrProjectionTimelineHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array{horizon: int, approach: string, months: list<array<string, mixed>>, dscr: array<string, mixed>}
     */
    public function handle(GetDscrProjectionTimelineQuery $query): array
    {
        $horizon = $query->horizon === 6 ? 6 : 12;
        $approach = $query->approach === 'operating' ? 'operating' : 'treasury';
        $snapshot = $this->engine->evaluate($this->engine->load($query->tenant, $query->asOf));

        return [
            'horizon' => $horizon,
            'approach' => $approach,
            'months' => array_slice($snapshot['timeline'], 0, $horizon),
            'dscr' => $snapshot['dscr'][$approach]['m'.$horizon],
        ];
    }
}
