<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

use Modules\ShiftPlanning\Domain\Enums\SwapStatus;

/**
 * The swap requests of the tenant (manager inbox, with the compliance impact of approving) or of one employee: the requests
 * the employee made and those waiting for the employee's answer.
 */
final readonly class ListShiftSwapRequestsQuery
{
    public function __construct(
        public string $tenantId,
        public ?SwapStatus $status = null,
        public ?string $employeeId = null,
        public bool $withImpact = false,
    ) {}
}
