<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum SettlementStatus: string
{
    use EnumValues;

    case Open = 'open';
    case Invoiced = 'invoiced';
}
