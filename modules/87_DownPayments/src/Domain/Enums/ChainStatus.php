<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** Lifecycle of a down-payment chain. */
enum ChainStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
    case Forfeited = 'forfeited';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
