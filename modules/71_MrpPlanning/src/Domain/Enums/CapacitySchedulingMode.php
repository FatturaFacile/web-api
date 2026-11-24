<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Infinite capacity only reports the load; finite capacity levels it against the available minutes. */
enum CapacitySchedulingMode: string
{
    case InfiniteCapacity = 'InfiniteCapacity';
    case FiniteCapacityConstraint = 'FiniteCapacityConstraint';
}
