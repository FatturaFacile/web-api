<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** Earnest money (caparra confirmatoria, Art. 1385 c.c. / Art. 158 CO) or security deposit (deposito cauzionale). */
enum DepositType: string
{
    case EarnestMoney = 'earnest_money_caparra_confirmatoria';
    case SecurityDeposit = 'security_deposit_cauzionale';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
