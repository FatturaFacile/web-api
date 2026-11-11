<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

/**
 * What a pair of accounts of two entities of a group represents. Account A belongs to entity A of the relation and
 * account B to entity B.
 *
 * ReceivablePayable: one account is an asset (receivable) and the other a liability (payable).
 * RevenueCost: one account is a revenue and the other a cost of goods sold or an expense.
 * InvestmentEquity: entity A is the investor and its account is the investment (asset); the account of entity B is an
 * equity account of the investee (share capital, share premium: the accounts that existed at acquisition). It is
 * optional for an investee consolidated with the equity method.
 */
enum IntercompanyMappingType: string
{
    case ReceivablePayable = 'receivable_payable';
    case RevenueCost = 'revenue_cost';
    case InvestmentEquity = 'investment_equity';
}
