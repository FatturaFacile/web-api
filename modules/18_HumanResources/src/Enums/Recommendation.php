<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum Recommendation: string
{
    case StrongHire = 'strong_hire';
    case Hire = 'hire';
    case NoHire = 'no_hire';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
