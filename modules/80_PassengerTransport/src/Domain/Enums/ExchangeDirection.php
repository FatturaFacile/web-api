<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum ExchangeDirection: string
{
    use EnumValues;

    case None = 'none';
    case Ceded = 'ceded';
    case Acquired = 'acquired';
}
