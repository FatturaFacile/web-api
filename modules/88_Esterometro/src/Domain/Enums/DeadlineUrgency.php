<?php

declare(strict_types=1);

namespace Modules\Esterometro\Domain\Enums;

enum DeadlineUrgency: string
{
    case Overdue = 'OVERDUE';
    case DueSoon = 'DUE_SOON';
    case Upcoming = 'UPCOMING';
    case Transmitted = 'TRANSMITTED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
