<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum Standard: string
{
    case Fer30 = 'fer30';
    case Ifrs10 = 'ifrs10';
    case Oic17 = 'oic17';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
