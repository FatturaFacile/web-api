<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum AllocationMode: string
{
    use EnumValues;

    case SharedPool = 'shared_pool';
    case Percentage = 'percentage';
    case FixedReserve = 'fixed_reserve';
    case PriorityRank = 'priority_rank';
}
