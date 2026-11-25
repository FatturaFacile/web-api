<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum PaymentMethod: string
{
    use Concerns\EnumValues;

    case SepaSdd = 'sepa_sdd';
    case LsvPlus = 'lsv_plus';
    case BankTransfer = 'bank_transfer';
}
