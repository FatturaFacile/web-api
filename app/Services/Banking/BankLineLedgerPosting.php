<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Services\TreasuryLedgerAccounts;
use Modules\Treasury\Services\TreasuryLedgerPoster;

/**
 * Posts a statement line categorised without an invoice (taxes, payroll, bank charges, internal transfers, other
 * expenses) to the general ledger, once per line:
 *
 * - outflow: Dr counter account / Cr bank for the amount, and Dr bank charges / Cr bank for the fee the bank took;
 * - inflow (internal transfers only): Dr bank / Cr counter account.
 *
 * The counter account is the one chosen on the line or the account configured for the category in
 * `banking.ledger_defaults`; the bank is the ledger account of the statement IBAN (LedgerAccountResolver, as for the
 * payments matched from statements). An account that is not configured or not in the chart refuses the posting.
 */
final readonly class BankLineLedgerPosting
{
    public const string SOURCE = 'bank_line_categorization';

    public function __construct(
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $accounts,
        private LedgerAccountResolver $resolver,
    ) {}

    /**
     * The counter account code of a category: the explicit one, else the configured one.
     *
     * @throws TreasuryOperationException when neither exists
     */
    public function counterAccountCode(string $category, ?string $explicitCode, ReconciliationSettings $settings): string
    {
        $code = $explicitCode !== null && $explicitCode !== '' ? $explicitCode : $settings->accountFor($category);

        if ($code === null) {
            throw new TreasuryOperationException(__('banking.errors.ledger_account_not_configured', ['setting' => ReconciliationSettings::settingFor($category)]), 'ledger_account_code');
        }

        return $code;
    }

    /**
     * Posts the categorised line and returns its entry.
     *
     * @throws TreasuryOperationException when an account is missing or the period is closed
     */
    public function post(BankStatementLine $line, BankStatement $statement, string $counterAccountCode, ReconciliationSettings $settings): JournalEntry
    {
        $tenantId = (string) $line->tenant_id;
        $counter = $this->accounts->byCode($tenantId, $counterAccountCode, 'ledger_account_code');
        $bank = $this->resolver->bank($tenantId, (string) $statement->currency, (string) $statement->account_iban);
        $amount = bcadd((string) $line->amount, '0', 2);
        $fee = bcadd((string) $line->fee_amount, '0', 2);
        $label = trim(((string) $line->reconciliation_category).': '.(string) ($line->description ?: $line->reference), ': ');
        $currency = (string) $statement->currency;

        $lines = $line->isCredit()
            ? [$this->line($bank, $amount, '0', $label, $currency), $this->line($counter, '0', $amount, $label, $currency)]
            : [$this->line($counter, $amount, '0', $label, $currency), $this->line($bank, '0', $amount, $label, $currency)];

        if (! $line->isCredit() && bccomp($fee, '0', 2) === 1) {
            $chargesCode = $settings->accountFor(BankStatementLine::CATEGORY_BANK_EXPENSE)
                ?? throw new TreasuryOperationException(__('banking.errors.ledger_account_not_configured', ['setting' => 'bank_charges_account_code']), 'ledger_account_code');
            $charges = $this->accounts->byCode($tenantId, $chargesCode, 'ledger_account_code');
            $lines[] = $this->line($charges, $fee, '0', "Bank fee: {$label}", $currency);
            $lines[] = $this->line($bank, '0', $fee, "Bank fee: {$label}", $currency);
        }

        return $this->poster->post(
            $tenantId,
            self::SOURCE,
            (string) $line->id,
            CarbonImmutable::parse($line->booking_date)->toDateString(),
            (string) ($line->reference ?: $statement->account_iban),
            "Bank statement line {$line->line_number}: {$label}",
            $lines,
            false,
        );
    }

    /**
     * Reverses the entry of a line whose categorisation is undone, on the booking date of the line.
     *
     * @throws TreasuryOperationException when the period of the line is closed
     */
    public function reverse(BankStatementLine $line): void
    {
        $this->poster->reverse((string) $line->tenant_id, self::SOURCE, (string) $line->id, CarbonImmutable::parse($line->booking_date)->toDateString(), 'Categorisation undone', false);
    }

    /**
     * @return array{account: ChartOfAccount, debit: string, credit: string, description: string, currency: string}
     */
    private function line(ChartOfAccount $account, string $debit, string $credit, string $description, string $currency): array
    {
        return ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $description, 'currency' => $currency];
    }
}
