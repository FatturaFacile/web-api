<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum BillingFrequency: string
{
    use EnumValues;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
