<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Online = 'online';
    case Cheque = 'cheque';

    /**
     * The code the payments of the company use (FatturaPA modalities, four characters).
     */
    public function ledgerCode(): string
    {
        return match ($this) {
            self::Cash => 'MP01',
            self::BankTransfer => 'MP05',
            self::Card, self::Online => 'MP08',
            self::Cheque => 'MP02',
        };
    }
}
