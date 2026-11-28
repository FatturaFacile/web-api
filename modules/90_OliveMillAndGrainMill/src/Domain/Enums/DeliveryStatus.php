<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum DeliveryStatus: string
{
    case Booked = 'BOOKED';
    case Weighed = 'WEIGHED';
    case InHopper = 'IN_HOPPER';
    case Milled = 'MILLED';
    case Settled = 'SETTLED';
    case Cancelled = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
