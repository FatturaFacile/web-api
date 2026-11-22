<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** How a net requirement becomes an order quantity. */
enum LotSizingRule: string
{
    case LotForLot = 'LotForLot';
    case MinimumOrderQuantity = 'MinimumOrderQuantity';
    case OrderMultiple = 'OrderMultiple';
    case EconomicOrderQuantity = 'EconomicOrderQuantity';
    case FixedDaysOfSupply = 'FixedDaysOfSupply';
}
