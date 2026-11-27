<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

enum PeriodType: string
{
    case Annual = 'ANNUAL';
    case Quarterly = 'QUARTERLY';
}
