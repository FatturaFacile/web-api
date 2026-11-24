<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BankStatementLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\PracticeManagement\Models\PracticeTrustPosting;
use Modules\PracticeManagement\Models\PracticeTrustTransaction;

/**
 * Posts the rows of the client funds ledger to the general ledger through its posting contract, and reconciles the
 * rows that moved money with the bank statement lines. The accounts are chosen by the caller: the module assumes no
 * chart of accounts. A deposit debits the bank and credits the client funds liability, a withdrawal does the opposite,
 * a fee transfer moves the funds from the liability to the fee revenue, and a reversal reverses the journal entry of
 * the row it reverses.
 */
final class TrustLedgerPostingService
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $posting,
        private readonly FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{bank_account_id?: ?string, client_funds_account_id?: ?string, fee_revenue_account_id?: ?string}  $accounts
     */
    public function post(PracticeTrustTransaction $transaction, array $accounts, ?string $userId): PracticeTrustPosting
    {
        return DB::transaction(function () use ($transaction, $accounts, $userId): PracticeTrustPosting {
            if (PracticeTrustPosting::query()->where('trust_transaction_id', $transaction->id)->lockForUpdate()->exists()) {
                throw new BusinessRuleException('The client funds row is already posted to the general ledger.');
            }

            $date = $transaction->occurred_on->toDateString();
            $tenantId = (string) $transaction->tenant_id;

            if ($transaction->type === 'reversal') {
                $original = PracticeTrustPosting::query()->where('trust_transaction_id', $transaction->reverses_id)->first();

                if ($original === null || $original->journal_entry_id === null) {
                    throw new BusinessRuleException('The row it reverses is not posted to the general ledger yet.');
                }

                $journal = $this->posting->reverseJournal((string) $original->journal_entry_id, $date, 'Client funds reversal', $userId);
            } else {
                $journal = $this->posting->createAndPost([
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'fiscal_period_id' => $this->periods->forDate($tenantId, $date)->id,
                    'posting_date' => $date,
                    'document_date' => $date,
                    'entry_number' => 'TRUST-'.Str::upper(substr((string) $transaction->id, -12)),
                    'source_document_type' => 'practice_trust_transaction',
                    'source_document_id' => (string) $transaction->id,
                    'journal_type' => 'standard',
                    'description' => 'Client funds '.$transaction->type.($transaction->reference === null ? '' : ' '.$transaction->reference),
                    'lines' => $this->lines($transaction, $accounts),
                ], $userId);
            }

            $record = new PracticeTrustPosting;
            $record->fill(['trust_transaction_id' => $transaction->id, 'journal_entry_id' => $journal->id, 'posted_at' => now(), 'posted_by' => $userId]);
            $record->save();

            return $record;
        });
    }

    /**
     * Matches a posted row that moved money with a bank statement line: a credit for incoming funds, a debit for outgoing
     * ones, of the same amount.
     */
    public function reconcile(PracticeTrustTransaction $transaction, string $bankStatementLineId): PracticeTrustPosting
    {
        return DB::transaction(function () use ($transaction, $bankStatementLineId): PracticeTrustPosting {
            $posting = PracticeTrustPosting::query()->where('trust_transaction_id', $transaction->id)->lockForUpdate()->first();

            if ($posting === null || $posting->journal_entry_id === null) {
                throw new BusinessRuleException('Post the client funds row to the general ledger before reconciling it with the bank.');
            }

            if ($posting->bank_statement_line_id !== null) {
                throw new BusinessRuleException('The client funds row is already reconciled with a bank line.');
            }

            $moved = $transaction->type === 'reversal'
                ? PracticeTrustTransaction::query()->findOrFail($transaction->reverses_id)->type
                : $transaction->type;

            if ($moved === 'fee_transfer') {
                throw new BusinessRuleException('A transfer to fees moves no money through the bank.');
            }

            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($bankStatementLineId);

            if ($line->reconciled) {
                throw new BusinessRuleException('The bank line is already reconciled.');
            }

            $incoming = ! Decimal::isNegative((string) $transaction->signed_amount);

            if ($line->direction !== ($incoming ? BankStatementLine::CREDIT : BankStatementLine::DEBIT)) {
                throw new BusinessRuleException('The direction of the bank line does not match the client funds row.');
            }

            if (Decimal::round2((string) $line->amount) !== Decimal::round2((string) $transaction->amount)) {
                throw new BusinessRuleException('The amount of the bank line does not match the client funds row.');
            }

            $line->forceFill([
                'reconciled' => true,
                'reconciliation_type' => BankStatementLine::MANUAL,
                'journal_entry_id' => $posting->journal_entry_id,
                'reconciliation_notes' => 'Client funds ledger row '.$transaction->id,
            ])->save();
            $posting->forceFill(['bank_statement_line_id' => $line->id, 'reconciled_at' => now()])->save();

            return $posting;
        });
    }

    /**
     * @param  array{bank_account_id?: ?string, client_funds_account_id?: ?string, fee_revenue_account_id?: ?string}  $accounts
     * @return list<array<string, mixed>>
     */
    private function lines(PracticeTrustTransaction $transaction, array $accounts): array
    {
        $funds = $accounts['client_funds_account_id'] ?? throw new BusinessRuleException('The client funds account is required.');
        $other = $transaction->type === 'fee_transfer'
            ? ($accounts['fee_revenue_account_id'] ?? throw new BusinessRuleException('The fee revenue account is required for a transfer to fees.'))
            : ($accounts['bank_account_id'] ?? throw new BusinessRuleException('The bank account is required.'));
        $amount = (float) Decimal::round2((string) $transaction->amount);
        $debitFunds = $transaction->type !== 'deposit';

        return [
            ['account_id' => $funds, 'debit_amount' => $debitFunds ? $amount : 0.0, 'credit_amount' => $debitFunds ? 0.0 : $amount, 'description' => 'Client funds held'],
            ['account_id' => $other, 'debit_amount' => $debitFunds ? 0.0 : $amount, 'credit_amount' => $debitFunds ? $amount : 0.0, 'description' => $transaction->type === 'fee_transfer' ? 'Fees collected from client funds' : 'Bank'],
        ];
    }
}
