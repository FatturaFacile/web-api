<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * State of a monthly instructor payout statement.
 */
enum PayoutStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case PAID = 'PAID';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
