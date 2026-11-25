<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum Jurisdiction: string
{
    use EnumValues;

    case It = 'IT';
    case Ch = 'CH';
}
