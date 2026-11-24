<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

/**
 * The SQL every ledger reader sums: the debit and credit of a line in the accounting (functional) currency of the
 * tenant. Balances, statements, closings, checks and exports read these expressions instead of the transaction-currency
 * `debit_amount` / `credit_amount`, which on a foreign-currency line are not in the accounting currency.
 *
 * A line posted since the functional columns exist always carries them. A line of the history gets them from the
 * backfill; the only lines left without are the foreign-currency ones the backfill could not convert (no usable rate,
 * listed by `general-ledger:functional-currency-audit`). For those the expressions fall back to the amounts as booked,
 * which is what every report showed before: no rate is guessed, and the figure of such a line does not change until it
 * is corrected with an entry.
 */
final class FunctionalAmount
{
    /**
     * Debit of a line in the accounting currency, for a table or alias of `journal_lines`.
     */
    public static function debit(string $table = 'journal_lines'): string
    {
        return "COALESCE({$table}.functional_debit, {$table}.debit_amount)";
    }

    /**
     * Credit of a line in the accounting currency, for a table or alias of `journal_lines`.
     */
    public static function credit(string $table = 'journal_lines'): string
    {
        return "COALESCE({$table}.functional_credit, {$table}.credit_amount)";
    }

    /**
     * Debit minus credit of a line in the accounting currency.
     */
    public static function balance(string $table = 'journal_lines'): string
    {
        return '('.self::debit($table).' - '.self::credit($table).')';
    }

    /**
     * The open amount of an open item (`customer_ledger` / `supplier_ledger`) in the accounting currency: as it is for an
     * item in the accounting currency, otherwise at the historical rate of the journal line that opened it, which is
     * the value the control account carries for it (payments settle the item at that same rate, the exchange difference
     * goes to its own account). It lets a control account be compared with its open items like with like. An item whose
     * opening line cannot be found keeps its open amount.
     *
     * @param  string  $table  `customer_ledger` or `supplier_ledger` (or an alias of them)
     * @param  string  $partnerColumn  `customer_id` or `supplier_id`
     */
    public static function openItem(string $table, string $partnerColumn, string $accountingCurrency): string
    {
        $currency = preg_match('/^[A-Z]{3}$/', strtoupper($accountingCurrency)) === 1 ? strtoupper($accountingCurrency) : 'EUR';

        return "CASE WHEN UPPER({$table}.currency) = '{$currency}' THEN {$table}.open_amount ELSE ROUND({$table}.open_amount * COALESCE((SELECT opening.exchange_rate FROM journal_lines opening WHERE opening.journal_entry_id = {$table}.journal_entry_id AND opening.partner_id = {$table}.{$partnerColumn} AND opening.deleted_at IS NULL ORDER BY opening.line_number LIMIT 1), 1), 4) END";
    }
}
