<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum DsarRequestType: string
{
    case AccessPortability = 'access_portability';
    case Rectification = 'rectification';
    case ErasureRightToBeForgotten = 'erasure';
    case RestrictionOfProcessing = 'restriction';
    case Objection = 'objection';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
