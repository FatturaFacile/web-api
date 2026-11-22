<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Queries;

/**
 * Traceability summary of a phase (CQRS read side): who worked it, the intervals, the pieces and the lots consumed.
 */
final readonly class GetPhaseTraceabilityQuery
{
    public function __construct(
        public string $tenantId,
        public string $phaseId,
    ) {}
}
