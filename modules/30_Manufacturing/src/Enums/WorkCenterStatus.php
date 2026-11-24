<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Lifecycle of a work centre or machine.
 */
enum WorkCenterStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
    case Decommissioned = 'decommissioned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
