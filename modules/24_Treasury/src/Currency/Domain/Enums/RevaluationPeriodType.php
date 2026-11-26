<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum RevaluationPeriodType: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
}
