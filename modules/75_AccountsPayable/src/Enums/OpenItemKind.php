<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Enums;

/**
 * A supplier open item is either a debit (an invoice we owe) or a credit note the supplier owes us.
 */
enum OpenItemKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
