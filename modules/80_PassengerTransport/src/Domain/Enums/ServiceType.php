<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum ServiceType: string
{
    use EnumValues;

    case AirportTransfer = 'airport_transfer';
    case HourlyDisposal = 'hourly_disposal';
    case MultiDayTourCoach = 'multi_day_tour_coach';
    case SchoolBusRoute = 'school_bus_route';
    case MedicalNonEmergency = 'medical_non_emergency';
}
