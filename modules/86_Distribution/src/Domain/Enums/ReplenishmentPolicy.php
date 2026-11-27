<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum ReplenishmentPolicy: string
{
    use EnumValues;

    case MinMax = 'min_max';
    case ReorderPointFixedQty = 'reorder_point_fixed_qty';
    case PeriodicReview = 'periodic_review';
    case DemandDrivenDrp = 'demand_driven_drp';
}
