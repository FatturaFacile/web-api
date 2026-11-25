<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum BillingCycle: string
{
    use EnumValues;

    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Immediate = 'IMMEDIATE';
}
