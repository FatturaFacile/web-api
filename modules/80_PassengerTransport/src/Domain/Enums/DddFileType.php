<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum DddFileType: string
{
    use EnumValues;

    case DriverCard = 'driver_card';
    case VehicleUnit = 'vehicle_unit';
}
