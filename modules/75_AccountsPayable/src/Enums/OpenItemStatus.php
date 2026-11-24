<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Enums;

/**
 * Where a supplier open item stands on its way to payment. A held item (disputed or administratively blocked) can never
 * be proposed, authorized or paid until the hold is released with an audited reason.
 */
enum OpenItemStatus: string
{
    case Open = 'open';
    case InProposal = 'in_proposal';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Disputed = 'disputed';
    case Blocked = 'blocked';

    public function isHold(): bool
    {
        return $this === self::Disputed || $this === self::Blocked;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
