<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum AlertType: string
{
    use EnumValues;

    case StockoutRisk = 'stockout_risk';
    case ExcessStock = 'excess_stock';
    case LateInbound = 'late_inbound';
}
