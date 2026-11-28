<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Fulfilment state of a package item.
 */
enum PackageItemStatus: string
{
    case RESERVED = 'RESERVED';
    case DELIVERED = 'DELIVERED';
    case RETURNED = 'RETURNED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
