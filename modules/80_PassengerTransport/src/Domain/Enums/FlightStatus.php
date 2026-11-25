<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum FlightStatus: string
{
    use EnumValues;

    case Scheduled = 'scheduled';
    case EnRoute = 'en_route';
    case Delayed = 'delayed';
    case Landed = 'landed';
    case Cancelled = 'cancelled';
}
