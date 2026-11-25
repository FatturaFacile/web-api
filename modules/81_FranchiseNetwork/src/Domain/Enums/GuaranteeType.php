<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum GuaranteeType: string
{
    use Concerns\EnumValues;

    case None = 'none';
    case BankGuarantee = 'bank_guarantee';
    case Insurance = 'insurance';
    case Deposit = 'deposit';
}
