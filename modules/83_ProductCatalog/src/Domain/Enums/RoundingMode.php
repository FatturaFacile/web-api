<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * How a converted quantity is brought to the precision of the unit. `None` refuses any quantity that does not fit.
 */
enum RoundingMode: string
{
    use EnumValues;

    case HalfUp = 'half_up';
    case Up = 'up';
    case Down = 'down';
    case None = 'none';
}
