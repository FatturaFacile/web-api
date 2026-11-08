<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Events\FXAdjustmentCalculatedEvent;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\FxRevaluation;

/**
 * Period-end revaluation of the bank accounts held in a foreign currency.
 *
 * For each active bank account in a currency other than the accounting currency, linked to a ledger account: the
 * foreign balance is the sum of the ledger lines in that currency up to the period end; the book value is the sum of
 * the accounting-currency amounts of every line of the account; the revalued value is the foreign balance at the
 * closing rate of the exchange-rate service (`exchange_rates`, base = accounting currency, latest rate on or before the
 * period end; without a rate nothing is revalued). The difference is posted as an unrealised gain (Dr bank / Cr
 * `fx_unrealised_gain_account_code`) or loss (Dr `fx_unrealised_loss_account_code` / Cr bank), in the accounting
 * currency, once per account and period end. The balances of the treasury bank accounts (foreign currency) do not move.
 */
final readonly class BankFxRevaluationService
{
    public function __construct(
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $accounts,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Revalues the foreign-currency bank accounts at a period end, optionally only those in one currency.
     *
     * @return list<FxRevaluation>
     *
     * @throws TreasuryOperationException when a rate or a ledger account is missing
     */
    public function revalue(string $tenantId, string $periodEndDate, ?string $currency, ?string $userId): array
    {
        $date = CarbonImmutable::parse($periodEndDate)->toDateString();
        $base = TenantAccountingCurrency::of($tenantId);

        $accounts = BankAccount::query()
            ->where('is_active', true)
            ->where('currency', '!=', $base)
            ->when($currency !== null, fn ($query) => $query->where('currency', strtoupper((string) $currency)))
            ->orderBy('iban')
            ->get();

        if ($accounts->isEmpty()) {
            throw new TreasuryOperationException('No active bank account in a foreign currency to revalue.', 'currency');
        }

        return DB::transaction(fn (): array => $accounts->map(fn (BankAccount $account): FxRevaluation => $this->revalueAccount($tenantId, $account, $date, $base, $userId))->all());
    }

    private function revalueAccount(string $tenantId, BankAccount $account, string $date, string $base, ?string $userId): FxRevaluation
    {
        $existing = FxRevaluation::query()->where('bank_account_id', $account->id)->whereDate('period_end_date', $date)->first();

        if ($existing !== null) {
            return $existing;
        }

        $ledgerAccount = $this->accounts->bank($account, 'bank_account_id');
        $rate = $this->closingRate($base, (string) $account->currency, $date);
        [$foreign, $book] = $this->balances($tenantId, (string) $ledgerAccount->id, (string) $account->currency, $base, $date);
        $revalued = Decimal::round2(Decimal::div($foreign, $rate));
        $difference = bcsub($revalued, $book, 2);

        $revaluation = FxRevaluation::query()->create([
            'tenant_id' => $tenantId,
            'company_id' => $account->company_id,
            'bank_account_id' => $account->id,
            'period_end_date' => $date,
            'currency' => $account->currency,
            'closing_rate' => $rate,
            'foreign_amount' => $foreign,
            'book_value_base' => $book,
            'revalued_market_base' => $revalued,
            'unrealized_gain_loss' => $difference,
            'gl_journal_entry_id' => null,
        ]);

        if (bccomp($difference, '0', 2) !== 0) {
            $isGain = bccomp($difference, '0', 2) === 1;
            $counter = $this->accounts->configured($tenantId, $isGain ? 'fx_unrealised_gain_account_code' : 'fx_unrealised_loss_account_code', 'period_end_date');
            $amount = ltrim($difference, '-');
            $label = "FX revaluation {$account->currency} {$account->iban} at {$date} (rate {$rate})";
            $line = static fn ($ledger, string $debit, string $credit): array => ['account' => $ledger, 'debit' => $debit, 'credit' => $credit, 'description' => $label, 'currency' => $base];

            $entry = $this->poster->post($tenantId, 'treasury_fx_revaluation', (string) $revaluation->id, $date, "FX-{$account->currency}-{$date}", $label, $isGain
                ? [$line($ledgerAccount, $amount, '0'), $line($counter, '0', $amount)]
                : [$line($counter, $amount, '0'), $line($ledgerAccount, '0', $amount)], true, $userId);
            $revaluation->update(['gl_journal_entry_id' => $entry->id]);
        }

        $this->dispatcher->publishAndDispatch(new FXAdjustmentCalculatedEvent(
            revaluationId: (string) $revaluation->id,
            currency: (string) $account->currency,
            unrealizedGainLoss: (float) $difference,
            periodEndDate: $date,
            journalEntryId: $revaluation->gl_journal_entry_id,
            tenantId: $tenantId,
            companyId: (string) ($account->company_id ?? ''),
        ));

        return $revaluation->refresh();
    }

    /**
     * Units of the foreign currency for one unit of the accounting currency, from the exchange-rate service.
     *
     * @throws TreasuryOperationException when no rate was published on or before the date
     */
    private function closingRate(string $base, string $currency, string $date): string
    {
        $rate = ExchangeRate::query()->where('source', $base)->where('target', $currency)->whereDate('effective_date', '<=', $date)->latest('effective_date')->value('rate');

        if ($rate === null || bccomp((string) $rate, '0', 8) !== 1) {
            throw new TreasuryOperationException("No {$base}/{$currency} exchange rate on or before {$date}: synchronise the exchange rates first.", 'period_end_date');
        }

        return (string) $rate;
    }

    /**
     * The foreign balance and the accounting-currency book value of a ledger account at a date.
     *
     * @return array{0: string, 1: string}
     */
    private function balances(string $tenantId, string $ledgerAccountId, string $currency, string $base, string $date): array
    {
        $foreign = '0';
        $book = '0';

        $lines = JournalLine::withoutGlobalScopes()
            ->join('journal_entries as entry', 'entry.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $ledgerAccountId)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->whereNull('entry.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->whereDate('entry.posting_date', '<=', $date)
            ->get(['journal_lines.debit_amount', 'journal_lines.credit_amount', 'journal_lines.currency', 'journal_lines.amount_in_functional_currency']);

        foreach ($lines as $line) {
            $isDebit = bccomp((string) $line->debit_amount, '0', 4) === 1;
            $booked = (string) ($isDebit ? $line->debit_amount : $line->credit_amount);
            $functional = $line->currency === $base ? $booked : (string) $line->amount_in_functional_currency;
            $sign = $isDebit ? '1' : '-1';

            if ($line->currency === $currency) {
                $foreign = bcadd($foreign, bcmul($booked, $sign, 4), 4);
            }

            $book = bcadd($book, bcmul($functional, $sign, 4), 4);
        }

        return [Decimal::round2($foreign), Decimal::round2($book)];
    }
}
