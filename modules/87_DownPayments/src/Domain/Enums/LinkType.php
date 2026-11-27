<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** The document a link of the chain records. */
enum LinkType: string
{
    case AdvanceInvoiceTd02 = 'advance_invoice_td02';
    case ProformaAdvanceRequest = 'proforma_advance_request';
    case EarnestMoneyDeposit = 'earnest_money_deposit';
    case SecurityDeposit = 'security_deposit';
    case CreditNoteTd04 = 'credit_note_td04';
    case FinalSettlementTd01 = 'final_settlement_td01';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
