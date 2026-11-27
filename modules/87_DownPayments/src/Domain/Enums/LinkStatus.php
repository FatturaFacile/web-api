<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** State of a link of the chain. */
enum LinkStatus: string
{
    case Open = 'open';
    case Issued = 'issued';
    case PartiallyCredited = 'partially_credited';
    case Credited = 'credited';
    case Settled = 'settled';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
