<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\Enums;

enum ValuationMethod: string
{
    case Wac = 'WAC';
    case Fifo = 'FIFO';
    case Lifo = 'LIFO';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
