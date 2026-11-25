<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum CollectionMethod: string
{
    case Cash = 'cash';
    case PosCard = 'pos_card';
    case Twint = 'twint';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
