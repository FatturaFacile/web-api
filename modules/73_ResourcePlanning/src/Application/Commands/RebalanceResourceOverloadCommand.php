<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Enums\RebalanceStrategy;

/**
 * Moves, hands over, splits or resizes an allocation to relieve an overloaded week; drag and drop on the planning board
 * sends the same command.
 */
final readonly class RebalanceResourceOverloadCommand
{
    public function __construct(
        public string $tenantId,
        public string $allocationId,
        public RebalanceStrategy $strategy,
        public ?string $employeeId = null,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $endDate = null,
        public ?CarbonImmutable $splitDate = null,
        public ?int $shiftDays = null,
        public ?float $hoursPerDay = null,
    ) {}
}
