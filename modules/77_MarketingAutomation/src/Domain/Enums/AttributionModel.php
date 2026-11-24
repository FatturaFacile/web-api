<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum AttributionModel: string
{
    case FirstTouch = 'first_touch';
    case LastTouch = 'last_touch';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
