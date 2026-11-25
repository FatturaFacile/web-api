<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum EnvelopeType: string
{
    case Administrative = 'administrative';
    case Technical = 'technical';
    case Economic = 'economic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
