<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

/**
 * Class of an account for the cash flow statement and the ratios.
 */
enum ReportingClass: string
{
    case Cash = 'cash';
    case Receivable = 'receivable';
    case Inventory = 'inventory';
    case OtherCurrentAsset = 'other_current_asset';
    case NonCurrentAsset = 'non_current_asset';
    case CurrentLiability = 'current_liability';
    case NonCurrentLiability = 'non_current_liability';
    case Equity = 'equity';
    case NonCashExpense = 'non_cash_expense';

    /**
     * The class of an account: the one set by the user, or else a default from its type. The second value says whether
     * it was only assumed, so that the statements can list those accounts.
     *
     * @return array{0: ?self, 1: bool}
     */
    public static function of(object $account): array
    {
        $explicit = self::tryFrom((string) $account->reporting_class);

        if ($explicit !== null) {
            return [$explicit, false];
        }

        return [match (true) {
            $account->reconciliation_type === 'bank' => self::Cash,
            $account->reconciliation_type === 'customer' => self::Receivable,
            $account->reconciliation_type === 'inventory' => self::Inventory,
            $account->reconciliation_type === 'supplier' => self::CurrentLiability,
            $account->account_type === 'equity' => self::Equity,
            $account->account_type === 'liability' => self::CurrentLiability,
            in_array($account->account_type, ['asset', 'wip'], true) => self::OtherCurrentAsset,
            default => null,
        }, true];
    }
}
