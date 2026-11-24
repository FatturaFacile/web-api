<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum ChargeKind: string
{
    case Cost = 'cost';
    case Revenue = 'revenue';
}
