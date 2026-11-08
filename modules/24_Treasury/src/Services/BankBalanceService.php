<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\BankStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountBalance;

/**
 * Balances of the bank accounts at a date, side by side:
 *
 * - `book_balance`: the balance of the ledger account the bank account is linked to, from the posted journal lines up
 *   to the date, in the currency of the bank account (null when it is not linked);
 * - `statement_balance`: the closing balance of the last bank statement of its IBAN dated on or before the date;
 * - `snapshot_balance`: the end-of-day snapshot of the treasury balance for that date, when one was taken.
 *
 * The difference between book and statement balance is what is still to reconcile.
 */
final readonly class BankBalanceService
{
    /**
     * The balances of every active bank account at a date.
     *
     * @return list<array<string, mixed>>
     */
    public function balancesAt(string $tenantId, CarbonImmutable $date): array
    {
        return BankAccount::query()->where('is_active', true)->orderBy('currency')->orderBy('bank_name')->get()
            ->map(fn (BankAccount $account): array => $this->balanceOf($tenantId, $account, $date))
            ->values()
            ->all();
    }

    /**
     * The balances of one bank account at a date.
     *
     * @return array<string, mixed>
     */
    public function balanceOf(string $tenantId, BankAccount $account, CarbonImmutable $date): array
    {
        $statement = BankStatement::query()
            ->where('account_iban', strtoupper(str_replace(' ', '', (string) $account->iban)))
            ->whereDate('statement_date', '<=', $date->toDateString())
            ->whereNotNull('closing_balance')
            ->orderByDesc('statement_date')
            ->first();
        $snapshot = BankAccountBalance::query()->where('bank_account_id', $account->id)->whereDate('balance_date', $date->toDateString())->first();

        return [
            'bank_account_id' => $account->id,
            'bank_name' => $account->bank_name,
            'iban' => $account->iban,
            'currency' => $account->currency,
            'date' => $date->toDateString(),
            'book_balance' => $account->gl_account_id === null ? null : $this->openingBalance($tenantId, (string) $account->gl_account_id, (string) $account->currency, $date->addDay()),
            'statement_balance' => $statement === null ? null : Decimal::round2((string) $statement->closing_balance),
            'statement_date' => $statement === null ? null : CarbonImmutable::parse($statement->statement_date)->toDateString(),
            'snapshot_balance' => $snapshot === null ? null : Decimal::round2((string) $snapshot->ledger_balance),
            'current_balance' => Decimal::round2((string) $account->current_balance),
        ];
    }

    /**
     * The daily book balance of a bank account between two dates: the opening balance and one row per day with
     * ledger movements.
     *
     * @return array{opening_balance: ?string, days: list<array{date: string, debit: string, credit: string, balance: string}>}
     */
    public function history(string $tenantId, BankAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($account->gl_account_id === null) {
            return ['opening_balance' => null, 'days' => []];
        }

        return [
            'opening_balance' => $this->openingBalance($tenantId, (string) $account->gl_account_id, (string) $account->currency, $from),
            'days' => $this->ledgerBalance($tenantId, (string) $account->gl_account_id, (string) $account->currency, $from, $to)->values()->all(),
        ];
    }

    /**
     * The book balance before a date.
     */
    private function openingBalance(string $tenantId, string $ledgerAccountId, string $currency, CarbonImmutable $before): string
    {
        $balance = '0';

        foreach ($this->lines($tenantId, $ledgerAccountId, $currency)->whereDate('entry.posting_date', '<', $before->toDateString())->get(['journal_lines.debit_amount', 'journal_lines.credit_amount']) as $line) {
            $balance = Decimal::add($balance, Decimal::sub((string) $line->debit_amount, (string) $line->credit_amount));
        }

        return Decimal::round2($balance);
    }

    /**
     * Daily movements and running balance of a ledger account between two dates.
     *
     * @return Collection<int, array{date: string, debit: string, credit: string, balance: string}>
     */
    private function ledgerBalance(string $tenantId, string $ledgerAccountId, string $currency, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $running = $this->openingBalance($tenantId, $ledgerAccountId, $currency, $from);
        $days = $this->lines($tenantId, $ledgerAccountId, $currency)
            ->whereDate('entry.posting_date', '>=', $from->toDateString())
            ->whereDate('entry.posting_date', '<=', $to->toDateString())
            ->orderBy('entry.posting_date')
            ->get(['entry.posting_date', 'journal_lines.debit_amount', 'journal_lines.credit_amount'])
            ->groupBy(static fn ($line): string => CarbonImmutable::parse($line->posting_date)->toDateString());

        return $days->map(function (Collection $lines, string $date) use (&$running): array {
            $debit = $lines->reduce(static fn (string $sum, $line): string => Decimal::add($sum, (string) $line->debit_amount), '0');
            $credit = $lines->reduce(static fn (string $sum, $line): string => Decimal::add($sum, (string) $line->credit_amount), '0');
            $running = Decimal::add($running, Decimal::sub($debit, $credit));

            return ['date' => $date, 'debit' => Decimal::round2($debit), 'credit' => Decimal::round2($credit), 'balance' => Decimal::round2($running)];
        })->values();
    }

    /**
     * The posted lines of a ledger account in the bank currency.
     *
     * @return Builder<JournalLine>
     */
    private function lines(string $tenantId, string $ledgerAccountId, string $currency): Builder
    {
        return JournalLine::withoutGlobalScopes()
            ->join('journal_entries as entry', 'entry.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $ledgerAccountId)
            ->where('journal_lines.currency', $currency)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->whereNull('entry.deleted_at')
            ->whereNull('journal_lines.deleted_at');
    }
}
