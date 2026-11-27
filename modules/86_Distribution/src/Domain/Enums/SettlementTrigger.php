<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum SettlementTrigger: string
{
    use EnumValues;

    case OnConsumption = 'on_consumption';
    case MonthlyReport = 'monthly_report';
    case OnReturn = 'on_return';
}
