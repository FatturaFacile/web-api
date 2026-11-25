<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

enum StatementKind: string
{
    case BalanceSheet = 'balance_sheet';
    case IncomeStatement = 'income_statement';
    case CashFlow = 'cash_flow';
}
