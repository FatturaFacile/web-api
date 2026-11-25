<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum GarmentEventType: string
{
    case Registered = 'registered';
    case ReceivedSoiled = 'received_soiled';
    case WashStarted = 'wash_started';
    case DeliveredClean = 'delivered_clean';
    case Assigned = 'assigned';
    case Released = 'released';
    case PpeBlocked = 'ppe_blocked';
    case ReplacementApproved = 'replacement_approved';
    case SentToMending = 'sent_to_mending';
    case Condemned = 'condemned';
    case UnreturnedBilled = 'unreturned_billed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
