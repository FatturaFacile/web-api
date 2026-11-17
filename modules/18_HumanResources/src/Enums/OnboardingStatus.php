<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum OnboardingStatus: string
{
    case Started = 'started';
    case NotAvailable = 'not_available';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
