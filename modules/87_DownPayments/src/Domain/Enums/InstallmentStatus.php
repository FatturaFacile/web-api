<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** Lifecycle of an installment of the payment plan. */
enum InstallmentStatus: string
{
    case Planned = 'planned';
    case Requested = 'requested';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Converted = 'converted';
    case Forfeited = 'forfeited';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
