<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum DrivingRegime: string
{
    use EnumValues;

    case Olr1Ec561 = 'olr1_ec561';
    case Olr2 = 'olr2';
}
