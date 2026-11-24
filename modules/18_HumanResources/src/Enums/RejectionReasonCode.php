<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum RejectionReasonCode: string
{
    case NotQualified = 'not_qualified';
    case SalaryMismatch = 'salary_mismatch';
    case PositionFilled = 'position_filled';
    case Withdrawn = 'withdrawn';
    case NoShow = 'no_show';
    case CultureFit = 'culture_fit';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
