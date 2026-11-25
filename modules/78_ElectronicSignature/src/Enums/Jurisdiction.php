<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * The legal framework an envelope is signed under.
 */
enum Jurisdiction: string
{
    case Eidas = 'eIDAS';
    case Zertes = 'ZertES';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $jurisdiction): string => $jurisdiction->value, self::cases());
    }
}
