<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Queries;

use Modules\Beekeeping\Services\TraceabilityService;

final class GetTraceabilityHandler
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetTraceabilityQuery $query): array
    {
        return $query->direction === 'backward'
            ? $this->traceability->backward($query->reference)
            : $this->traceability->forward($query->type ?? 'apiary', $query->reference);
    }
}
