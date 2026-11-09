<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives the journal lines posted before the functional columns existed their debit and credit in the accounting
 * currency of their tenant, and lists what it could not convert.
 *
 * - a line in the accounting currency copies its amounts;
 * - a line in a foreign currency with a usable rate takes the stored conversion: `amount_in_functional_currency` on the
 *   side the line sits (it is what the posting computed, amount × rate at 4 decimals), else amount × `exchange_rate`;
 * - a line in a foreign currency whose rate is missing, not positive or exactly 1 (the value the posting service used
 *   to store when the caller gave no rate) is left null: no rate is guessed. Those lines are what
 *   `general-ledger:functional-currency-audit` reports.
 *
 * Only lines whose functional columns are both null are touched, so a second run changes nothing. The accounting
 * currency is the tenant's `default_currency`, EUR when it has none, as TenantAccountingCurrency reads it.
 */
final class FunctionalCurrencyBackfill
{
    /**
     * Fills the functional amounts that can be derived and returns how many lines each rule filled.
     *
     * @return array{accounting_currency: int, foreign_currency: int, unresolved: int}
     */
    public function run(?string $tenantId = null): array
    {
        $accounting = self::accountingCurrencySql();
        $tenantFilter = $tenantId !== null ? ' AND journal_lines.tenant_id = ?' : '';
        $bindings = $tenantId !== null ? [$tenantId] : [];

        $accountingLines = DB::update("
            UPDATE journal_lines
            SET functional_debit = debit_amount, functional_credit = credit_amount
            WHERE functional_debit IS NULL AND functional_credit IS NULL
              AND UPPER(journal_lines.currency) = {$accounting}{$tenantFilter}
        ", $bindings);

        $foreignLines = DB::update("
            UPDATE journal_lines
            SET functional_debit = CASE
                    WHEN debit_amount = 0 THEN 0
                    WHEN credit_amount = 0 AND amount_in_functional_currency > 0 THEN amount_in_functional_currency
                    ELSE ROUND(debit_amount * exchange_rate, 4)
                END,
                functional_credit = CASE
                    WHEN credit_amount = 0 THEN 0
                    WHEN debit_amount = 0 AND amount_in_functional_currency > 0 THEN amount_in_functional_currency
                    ELSE ROUND(credit_amount * exchange_rate, 4)
                END
            WHERE functional_debit IS NULL AND functional_credit IS NULL
              AND UPPER(journal_lines.currency) <> {$accounting}
              AND exchange_rate IS NOT NULL AND exchange_rate > 0 AND exchange_rate <> 1{$tenantFilter}
        ", $bindings);

        return [
            'accounting_currency' => $accountingLines,
            'foreign_currency' => $foreignLines,
            'unresolved' => $this->unresolvedQuery($tenantId)->count(),
        ];
    }

    /**
     * The lines left without functional amounts, with what an accountant needs to correct them.
     *
     * @return Collection<int, object{tenant_id: string, journal_entry_id: string, entry_number: string, posting_date: string, status: string, line_id: string, line_number: int, account_code: ?string, currency: string, debit_amount: string, credit_amount: string, exchange_rate: ?string}>
     */
    public function unresolved(?string $tenantId = null): Collection
    {
        /** @var Collection<int, object{tenant_id: string, journal_entry_id: string, entry_number: string, posting_date: string, status: string, line_id: string, line_number: int, account_code: ?string, currency: string, debit_amount: string, credit_amount: string, exchange_rate: ?string}> */
        return $this->unresolvedQuery($tenantId)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->orderBy('journal_lines.tenant_id')
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.entry_number')
            ->orderBy('journal_lines.line_number')
            ->get([
                'journal_lines.tenant_id', 'journal_lines.journal_entry_id', 'journal_entries.entry_number', 'journal_entries.posting_date',
                'journal_entries.status', 'journal_lines.id as line_id', 'journal_lines.line_number', 'chart_of_accounts.account_code',
                'journal_lines.currency', 'journal_lines.debit_amount', 'journal_lines.credit_amount', 'journal_lines.exchange_rate',
            ]);
    }

    /**
     * The posted entries whose lines do not balance in the accounting currency: conversion rounding of the history
     * (each line converted on its own) or lines still without functional amounts. They are corrected with an entry,
     * never rewritten.
     *
     * @return Collection<int, object{tenant_id: string, journal_entry_id: string, entry_number: string, posting_date: string, functional_debit: string, functional_credit: string, difference: string}>
     */
    public function unbalancedEntries(?string $tenantId = null): Collection
    {
        $debit = FunctionalAmount::debit();
        $credit = FunctionalAmount::credit();

        /** @var Collection<int, object{tenant_id: string, journal_entry_id: string, entry_number: string, posting_date: string, functional_debit: string, functional_credit: string, difference: string}> */
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_lines.deleted_at')
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($tenantId !== null, fn ($query) => $query->where('journal_lines.tenant_id', $tenantId))
            ->groupBy('journal_lines.tenant_id', 'journal_entries.id', 'journal_entries.entry_number', 'journal_entries.posting_date')
            ->havingRaw("SUM({$debit}) <> SUM({$credit})")
            ->orderBy('journal_lines.tenant_id')
            ->orderBy('journal_entries.posting_date')
            ->selectRaw("journal_lines.tenant_id, journal_entries.id as journal_entry_id, journal_entries.entry_number, journal_entries.posting_date, SUM({$debit}) as functional_debit, SUM({$credit}) as functional_credit, SUM({$debit}) - SUM({$credit}) as difference")
            ->get();
    }

    private function unresolvedQuery(?string $tenantId): Builder
    {
        return DB::table('journal_lines')
            ->whereNull('journal_lines.functional_debit')
            ->whereNull('journal_lines.functional_credit')
            ->whereNull('journal_lines.deleted_at')
            ->when($tenantId !== null, fn ($query) => $query->where('journal_lines.tenant_id', $tenantId));
    }

    /**
     * The accounting currency of the tenant of the line being updated, as SQL.
     */
    private static function accountingCurrencySql(): string
    {
        return "COALESCE((SELECT NULLIF(UPPER(tenants.default_currency), '') FROM tenants WHERE tenants.id = journal_lines.tenant_id), 'EUR')";
    }
}
