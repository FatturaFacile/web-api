<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum BreachIncidentStatus: string
{
    case Investigating = 'investigating';
    case Contained = 'contained';
    case NotifiedAuthority = 'notified_authority';
    case NotifiedSubjects = 'notified_subjects';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
