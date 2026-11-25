<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

/**
 * Which weighing a per-kg contract bills: the soiled linen received or the clean linen delivered.
 */
enum KgBasis: string
{
    case Dirty = 'dirty';
    case Clean = 'clean';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function direction(): ScanDirection
    {
        return $this === self::Dirty ? ScanDirection::InboundSoil : ScanDirection::OutboundClean;
    }
}
