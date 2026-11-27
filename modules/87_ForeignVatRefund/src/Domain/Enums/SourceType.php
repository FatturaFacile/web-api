<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

enum SourceType: string
{
    case PassiveInvoice = 'PASSIVE_INVOICE';
    case ExpenseReport = 'EXPENSE_REPORT';
    case Manual = 'MANUAL';
}
