<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Result of an inspection.
 */
enum InspectionOutcome: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
