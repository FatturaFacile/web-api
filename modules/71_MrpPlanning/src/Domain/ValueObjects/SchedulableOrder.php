<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A planned production order as the capacity scheduler sees it: the quantity, the due date and the routing.
 */
final readonly class SchedulableOrder
{
    /**
     * @param  list<RoutingOperation>  $operations
     */
    public function __construct(
        public string $proposalId,
        public string $quantity,
        public CarbonImmutable $needBy,
        public CarbonImmutable $plannedStart,
        public array $operations,
    ) {}
}
