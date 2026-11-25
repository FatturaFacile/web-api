<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

/**
 * Gesamtkostenverfahren / costo complessivo (nature of expense) or Umsatzkostenverfahren / costo del venduto (function of expense).
 * Italy uses the nature-of-expense scheme of Art. 2425 only; CO Art. 959b allows both.
 */
enum IncomeStatementMethod: string
{
    case TotalCost = 'total_cost';
    case CostOfSales = 'cost_of_sales';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
