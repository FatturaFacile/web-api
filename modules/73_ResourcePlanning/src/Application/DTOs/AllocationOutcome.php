<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\DTOs;

use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * What a booking command changed: the allocation the user acted on, every allocation that was written (two for a split),
 * the overload left over the touched weeks and the non-blocking findings.
 */
final readonly class AllocationOutcome
{
    /**
     * @param  list<ResourceAllocation>  $allocations
     * @param  list<array{code: string, params: array<string, scalar|null>}>  $warnings
     */
    public function __construct(
        public ResourceAllocation $allocation,
        public array $allocations,
        public OverloadReport $overload,
        public array $warnings = [],
    ) {}
}
