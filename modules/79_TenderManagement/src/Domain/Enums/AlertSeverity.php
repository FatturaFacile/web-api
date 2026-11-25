<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
