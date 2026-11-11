<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

/**
 * The consolidation adjustments a run generates, stored apart from the ledgers of the entities.
 */
enum ConsolidationAdjustmentType: string
{
    case IntercompanyBalance = 'intercompany_balance';
    case IntercompanyRevenueCost = 'intercompany_revenue_cost';
    case InvestmentEquity = 'investment_equity';
    case EquityMethod = 'equity_method';
    case TranslationDifference = 'translation_difference';
}
