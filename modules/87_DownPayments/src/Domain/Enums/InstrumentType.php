<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** What an installment produces when it is emitted. */
enum InstrumentType: string
{
    case AdvanceInvoiceImmediate = 'advance_invoice_immediate';
    case AdvanceProformaRequest = 'advance_proforma_request';
    case EarnestMoneyDeposit = 'earnest_money_deposit';
    case SecurityDeposit = 'security_deposit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
