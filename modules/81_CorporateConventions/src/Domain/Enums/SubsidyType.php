<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum SubsidyType: string
{
    use EnumValues;

    case FixedAmount = 'FIXED_AMOUNT';
    case Percentage = 'PERCENTAGE';
}
