<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Billing state of a crew route stop.
 */
enum BillingStatus: string
{
    case NotBilled = 'not_billed';
    case IncludedInContract = 'included_in_contract';
    case ReadyToInvoice = 'ready_to_invoice';
    case Invoiced = 'invoiced';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
