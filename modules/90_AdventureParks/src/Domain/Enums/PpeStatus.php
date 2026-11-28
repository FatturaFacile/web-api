<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Life-cycle status of a PPE item (EN 365 register).
 */
enum PpeStatus: string
{
    case Available = 'AVAILABLE';
    case InUse = 'IN_USE';
    case MaintenanceRequired = 'MAINTENANCE_REQUIRED';
    case ExpiredBlocked = 'EXPIRED_BLOCKED';
    case Decommissioned = 'DECOMMISSIONED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
