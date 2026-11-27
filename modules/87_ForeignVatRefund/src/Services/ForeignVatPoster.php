<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Illuminate\Support\Str;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Models\BankAccount;
use Throwable;

/**
 * Every journal entry of the module, in the accounting currency of the company:
 *
 * - recognition: the recoverable foreign VAT moves to the transitory receivable, the rest becomes a cost;
 * - rejection: the part the foreign authority refused moves from the receivable to the non recoverable expense;
 * - reconciliation: the bank receipt clears the receivable, with the exchange difference on the realised FX accounts.
 */
final class ForeignVatPoster
{
    public const string SOURCE_RECOGNITION = 'foreign_vat_recognition';

    public const string SOURCE_REJECTION = 'foreign_vat_rejection';

    public const string SOURCE_RECONCILIATION = 'foreign_vat_reconciliation';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $posting,
        private readonly FiscalPeriodResolver $periods,
        private readonly ForeignVatAccounts $accounts,
    ) {}

    /**
     * @param  PostingAccountRole  $creditRole  where the VAT sits before the move: the input VAT account (a passive invoice) or the expense account (an expense report)
     *
     * @throws ForeignVatException when the account of the role is not configured
     */
    public function postRecognition(ForeignVatLedgerEntry $entry, PostingAccountRole $creditRole, ?string $actorId = null): ?JournalEntry
    {
        $tenantId = (string) $entry->tenant_id;
        $recoverable = (string) $entry->base_recoverable_vat_amount;
        $total = (string) $entry->base_currency_foreign_vat_amount;
        $credit = $this->accounts->role($tenantId, $creditRole) ?? throw ForeignVatException::because('account_role_missing', ['role' => $creditRole->value]);
        $currency = TenantAccountingCurrency::of($tenantId);
        $lines = [];
        $costPart = Decimal::sub($total, $recoverable);
        $receivable = $this->accounts->receivable($tenantId);

        if (Decimal::isPositive($recoverable)) {
            $lines[] = $this->line($receivable->id, $recoverable, '0', $currency, 'Foreign VAT refundable '.$entry->supplier_country_code);
        }

        if ($creditRole === PostingAccountRole::InputVat) {
            if (Decimal::isPositive($costPart)) {
                $lines[] = $this->line($this->accounts->nonRecoverableExpense($tenantId)->id, Decimal::round2($costPart), '0', $currency, 'Non-recoverable foreign VAT '.$entry->supplier_country_code);
            }
            $creditAmount = $total;
        } else {
            $creditAmount = $recoverable;
        }

        if (! Decimal::isPositive($creditAmount) || $lines === []) {
            return null;
        }

        $lines[] = $this->line($credit->id, '0', Decimal::round2($creditAmount), $currency, 'Foreign VAT '.$entry->document_number);
        $date = $entry->document_date->format('Y-m-d');

        return $this->post($tenantId, $date, self::SOURCE_RECOGNITION, (string) $entry->id, $entry->document_number, 'Foreign VAT '.$entry->document_number, $lines, $actorId);
    }

    /**
     * The refused part: debit the non recoverable expense, credit the receivable.
     */
    public function postRejection(VatRefundClaim $claim, string $rejectedBase, string $date, ?string $actorId = null): ?JournalEntry
    {
        if (! Decimal::isPositive($rejectedBase)) {
            return null;
        }

        $tenantId = (string) $claim->tenant_id;
        $currency = TenantAccountingCurrency::of($tenantId);
        $lines = [
            $this->line($this->accounts->nonRecoverableExpense($tenantId)->id, $rejectedBase, '0', $currency, 'Refund refused '.$claim->claim_number),
            $this->line($this->accounts->receivable($tenantId)->id, '0', $rejectedBase, $currency, 'Refund refused '.$claim->claim_number),
        ];

        return $this->post($tenantId, $date, self::SOURCE_REJECTION, (string) $claim->id, $claim->claim_number, 'Foreign VAT refund refused '.$claim->claim_number, $lines, $actorId);
    }

    /**
     * The bank receipt: debit the bank, clear the receivable by what is still carried, reclassify a refusal not yet booked and
     * book the exchange difference.
     *
     * @throws ForeignVatException when the bank account is unknown or has no ledger account
     */
    public function postReconciliation(VatRefundClaim $claim, string $bankAccountId, string $bankAmountBase, string $rejectedBase, string $carriedBase, string $date, ?string $actorId = null): JournalEntry
    {
        $tenantId = (string) $claim->tenant_id;
        $bank = BankAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($bankAccountId)->first()
            ?? throw ForeignVatException::because('bank_account_not_found');
        $glAccount = $this->accounts->owned($tenantId, $bank->gl_account_id ?? null) ?? throw ForeignVatException::because('bank_account_without_ledger_account');
        $currency = TenantAccountingCurrency::of($tenantId);
        $label = 'Foreign VAT refund received '.$claim->claim_number;
        $lines = [$this->line($glAccount->id, $bankAmountBase, '0', $currency, $label)];

        if (Decimal::isPositive($rejectedBase)) {
            $lines[] = $this->line($this->accounts->nonRecoverableExpense($tenantId)->id, $rejectedBase, '0', $currency, 'Refund refused '.$claim->claim_number);
        }

        $lines[] = $this->line($this->accounts->receivable($tenantId)->id, '0', $carriedBase, $currency, $label);
        $difference = Decimal::sub(Decimal::add($bankAmountBase, $rejectedBase), $carriedBase);

        if (! Decimal::isZero($difference)) {
            $gain = Decimal::isPositive($difference);
            $fx = $this->accounts->role($tenantId, $gain ? PostingAccountRole::FxRealisedGain : PostingAccountRole::FxRealisedLoss)
                ?? $this->accounts->nonRecoverableExpense($tenantId);
            $amount = Decimal::round2(Decimal::abs($difference));
            $lines[] = $gain
                ? $this->line($fx->id, '0', $amount, $currency, 'Exchange gain '.$claim->claim_number)
                : $this->line($fx->id, $amount, '0', $currency, 'Exchange loss '.$claim->claim_number);
        }

        return $this->post($tenantId, $date, self::SOURCE_RECONCILIATION, (string) $claim->id, $claim->claim_number, $label, $lines, $actorId);
    }

    public function reverse(string $journalEntryId, string $reason, ?string $actorId = null): void
    {
        $this->posting->reverseJournal($journalEntryId, now()->toDateString(), $reason, $actorId);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(string $tenantId, string $date, string $source, string $sourceId, string $documentNumber, string $description, array $lines, ?string $actorId): JournalEntry
    {
        // A date in a missing or closed period is booked today, in the period of today.
        [$periodId, $postingDate] = $this->period($tenantId, $date);

        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $periodId,
            'entry_number' => 'FVAT-'.strtoupper(Str::random(8)),
            'source_document_type' => $source,
            'source_document_id' => $sourceId,
            'document_number' => $documentNumber,
            'posting_date' => $postingDate,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ], $actorId);
    }

    /**
     * @return array{0: string, 1: string} the id of the fiscal period and the posting date
     */
    private function period(string $tenantId, string $date): array
    {
        try {
            return [(string) $this->periods->forDate($tenantId, $date)->id, $date];
        } catch (Throwable) {
            $today = now()->toDateString();

            return [(string) $this->periods->forDate($tenantId, $today)->id, $today];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $accountId, string $debit, string $credit, string $currency, string $description): array
    {
        return ['account_id' => $accountId, 'debit_amount' => $debit, 'credit_amount' => $credit, 'currency' => $currency, 'description' => $description];
    }
}
