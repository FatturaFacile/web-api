<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum SheetStatus: string
{
    use EnumValues;

    case Open = 'open';
    case Closed = 'closed';
    case Transmitted = 'transmitted';
}
