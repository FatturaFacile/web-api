<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Enums;

/**
 * Analytical category of a ledger line in the data mart.
 */
enum LedgerCategory: string
{
    case Revenue = 'revenue';
    case Cogs = 'cogs';
    case Opex = 'opex';
    case Depreciation = 'depreciation';
    case Interest = 'interest';
    case Tax = 'tax';
    case Cash = 'cash';
    case Receivable = 'receivable';
    case Payable = 'payable';
    case Inventory = 'inventory';
    case Balance = 'balance';

    public function isProfitAndLoss(): bool
    {
        return in_array($this, [self::Revenue, self::Cogs, self::Opex, self::Depreciation, self::Interest, self::Tax], true);
    }
}
