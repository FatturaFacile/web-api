<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum ScanDirection: string
{
    case InboundSoil = 'inbound_soil';
    case OutboundClean = 'outbound_clean';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
