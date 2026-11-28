<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

use Modules\DairyCheeseManufacturing\Services\TraceabilityService;

final class TraceLineageHandler
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    /**
     * @return array{root: array<string, mixed>, summary: array<string, int|string>}
     */
    public function handle(TraceLineageQuery $query): array
    {
        return $this->traceability->trace($query->direction, $query->type, $query->id, $query->serial);
    }
}
