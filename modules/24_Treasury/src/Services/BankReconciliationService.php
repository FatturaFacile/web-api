<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Events\BankReconciliationCompletedEvent;
use Modules\Treasury\Events\BankStatementImportedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Treasury\Models\ReconciliationMatch;

final readonly class BankReconciliationService implements BankReconciliationServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function importStatement(array $statementData, array $lines): BankStatement
    {
        return DB::transaction(function () use ($statementData, $lines) {
            $bankAccount = BankAccount::findOrFail($statementData['bank_account_id']);
            $statementNo = $statementData['statement_number'] ?? 'STMT-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $totalDebits = 0.0;
            $totalCredits = 0.0;

            foreach ($lines as $line) {
                $amount = (float) $line['amount'];
                if ($amount >= 0) {
                    $totalCredits += $amount;
                } else {
                    $totalDebits += abs($amount);
                }
            }

            $opening = (float) ($statementData['opening_balance'] ?? $bankAccount->current_balance);
            $closing = (float) ($statementData['closing_balance'] ?? ($opening + $totalCredits - $totalDebits));

            /** @var BankStatement $statement */
            $statement = BankStatement::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $statementData['tenant_id'],
                'company_id' => $statementData['company_id'] ?? $bankAccount->company_id,
                'bank_account_id' => $bankAccount->id,
                'statement_number' => $statementNo,
                'source_format' => $statementData['format'] ?? 'CAMT_053',
                'account_iban' => $bankAccount->iban,
                'currency' => $bankAccount->currency,
                'total_entries' => count($lines),
                'content_hash' => hash('sha256', json_encode($lines).$statementNo),
                'status' => 'imported',
                'statement_date' => $statementData['statement_date'] ?? now()->toDateString(),
                'format' => $statementData['format'] ?? 'CAMT_053',
                'raw_file_storage_path' => $statementData['raw_file_storage_path'] ?? 'statements/'.$statementNo.'.xml',
                'file_sha256' => hash('sha256', json_encode($lines).$statementNo),
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'lines_count' => count($lines),
                'reconciliation_status' => 'unreconciled',
            ]);

            $lineNo = 1;
            foreach ($lines as $l) {
                $amt = (float) $l['amount'];
                BankStatementLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $statement->tenant_id,
                    'company_id' => $statement->company_id,
                    'bank_statement_id' => $statement->id,
                    'bank_account_id' => $bankAccount->id,
                    'line_number' => $lineNo++,
                    'booking_date' => $l['booking_date'] ?? $statement->statement_date,
                    'value_date' => $l['value_date'] ?? $statement->statement_date,
                    'amount' => $amt,
                    'fee_amount' => (float) ($l['fee_amount'] ?? 0.0),
                    'direction' => $amt >= 0 ? 'credit' : 'debit',
                    'bank_reference' => $l['bank_reference'] ?? null,
                    'customer_reference' => $l['customer_reference'] ?? null,
                    'counterparty_name' => $l['counterparty_name'] ?? null,
                    'counterparty_iban' => $l['counterparty_iban'] ?? null,
                    'counterparty_bic' => $l['counterparty_bic'] ?? null,
                    'remittance_information' => $l['remittance_information'] ?? null,
                    'iso_bank_code' => $l['iso_bank_code'] ?? 'PMNT',
                    'reconciled' => false,
                    'reconciled_status' => 'unmatched',
                    'reconciled_amount' => 0.00,
                ]);
            }

            $event = new BankStatementImportedEvent(
                statementId: (string) $statement->id,
                bankAccountId: (string) $bankAccount->id,
                statementNumber: $statementNo,
                statementDate: (string) $statement->statement_date,
                closingBalance: $closing,
                linesCount: count($lines),
                tenantId: (string) $statement->tenant_id,
                companyId: (string) ($statement->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'statement_imported',
                model: $statement,
                payloadBefore: null,
                payloadAfter: $statement->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $statement->load('lines');
        });
    }

    public function runAutoReconciliation(string $bankStatementId, ?string $userId = null): BankReconciliation
    {
        return DB::transaction(function () use ($bankStatementId, $userId) {
            /** @var BankStatement $statement */
            $statement = BankStatement::with('lines')->where('id', $bankStatementId)->lockForUpdate()->firstOrFail();
            $bankAccount = BankAccount::findOrFail($statement->bank_account_id);

            $reconNumber = 'REC-'.date('Ymd').'-'.strtoupper(Str::random(6));

            /** @var BankReconciliation $recon */
            $recon = BankReconciliation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $statement->tenant_id,
                'company_id' => $statement->company_id,
                'bank_account_id' => $bankAccount->id,
                'reconciliation_number' => $reconNumber,
                'period_start_date' => $statement->statement_date,
                'period_end_date' => $statement->statement_date,
                'bank_statement_balance' => (float) $statement->closing_balance,
                'gl_book_balance' => (float) $bankAccount->current_balance,
                'difference_amount' => (float) $statement->closing_balance - (float) $bankAccount->current_balance,
                'status' => 'in_progress',
            ]);

            $matchedCount = 0;
            $userUuid = ! empty($userId) && Str::isUuid($userId) ? $userId : (string) Str::uuid7();

            foreach ($statement->lines as $line) {
                if ($line->reconciled) {
                    continue;
                }

                $lineAmount = abs((float) $line->amount);

                // Pass 1: Exact reference match against PaymentLine
                $paymentLine = null;
                if (! empty($line->bank_reference) || ! empty($line->customer_reference)) {
                    $ref = $line->bank_reference ?? $line->customer_reference;
                    $paymentLine = PaymentLine::where('tenant_id', $statement->tenant_id)
                        ->where(function ($q) use ($ref) {
                            $q->where('instruction_id', $ref)
                                ->orWhere('end_to_end_id', $ref)
                                ->orWhere('remittance_reference', 'like', "%{$ref}%");
                        })
                        ->where('status', '!=', 'reconciled')
                        ->first();
                }

                if ($paymentLine) {
                    ReconciliationMatch::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $statement->tenant_id,
                        'bank_reconciliation_id' => $recon->id,
                        'bank_statement_line_id' => $line->id,
                        'matched_entity_type' => 'supplier_payment',
                        'matched_entity_id' => $paymentLine->id,
                        'match_type' => 'exact_ref',
                        'allocated_amount' => $lineAmount,
                        'confidence_score' => 100.00,
                        'reconciled_by' => $userUuid,
                    ]);

                    $line->update([
                        'reconciled' => true,
                        'reconciled_status' => 'matched',
                        'reconciled_amount' => $lineAmount,
                    ]);
                    $paymentLine->update(['status' => 'cleared']);
                    $matchedCount++;

                    continue;
                }

                // Pass 2: Exact Amount match against ReceivableCollection or JournalEntry
                if ($line->direction === 'credit') {
                    $collection = ReceivableCollection::where('tenant_id', $statement->tenant_id)
                        ->where('status', 'pending')
                        ->where('expected_amount', $lineAmount)
                        ->first();

                    if ($collection) {
                        ReconciliationMatch::create([
                            'id' => (string) Str::uuid7(),
                            'tenant_id' => $statement->tenant_id,
                            'bank_reconciliation_id' => $recon->id,
                            'bank_statement_line_id' => $line->id,
                            'matched_entity_type' => 'customer_payment',
                            'matched_entity_id' => $collection->id,
                            'match_type' => 'exact_amount_date',
                            'allocated_amount' => $lineAmount,
                            'confidence_score' => 95.00,
                            'reconciled_by' => $userUuid,
                        ]);

                        $line->update([
                            'reconciled' => true,
                            'reconciled_status' => 'matched',
                            'reconciled_amount' => $lineAmount,
                        ]);
                        $collection->update([
                            'status' => 'collected',
                            'collected_amount' => $lineAmount,
                            'collection_date' => $line->booking_date,
                        ]);
                        $matchedCount++;

                        continue;
                    }
                }
            }

            $unmatchedRemaining = BankStatementLine::where('bank_statement_id', $statement->id)
                ->where('reconciled', false)
                ->count();

            $statementStatus = $unmatchedRemaining === 0 ? 'reconciled' : ($matchedCount > 0 ? 'partially_reconciled' : 'unreconciled');
            $statement->update(['reconciliation_status' => $statementStatus]);

            if ($unmatchedRemaining === 0) {
                $recon->update([
                    'status' => 'completed',
                    'difference_amount' => 0.00,
                ]);
            }

            $event = new BankReconciliationCompletedEvent(
                reconciliationId: (string) $recon->id,
                bankAccountId: (string) $bankAccount->id,
                periodEndDate: (string) $recon->period_end_date,
                statementBalance: (float) $recon->bank_statement_balance,
                glBookBalance: (float) $recon->gl_book_balance,
                differenceAmount: (float) $recon->difference_amount,
                tenantId: (string) $recon->tenant_id,
                companyId: (string) ($recon->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'reconciliation_executed',
                model: $recon,
                payloadBefore: null,
                payloadAfter: $recon->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $recon->load('matches');
        });
    }

    public function matchManually(string $bankStatementLineId, array $matchData, string $userId): void
    {
        DB::transaction(function () use ($bankStatementLineId, $matchData, $userId) {
            /** @var BankStatementLine $line */
            $line = BankStatementLine::where('id', $bankStatementLineId)->lockForUpdate()->firstOrFail();

            if ($line->reconciled) {
                throw new InvalidArgumentException('Bank statement line is already reconciled.');
            }

            $amount = (float) ($matchData['allocated_amount'] ?? abs((float) $line->amount));
            $userUuid = Str::isUuid($userId) ? $userId : (string) Str::uuid7();

            ReconciliationMatch::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $line->tenant_id,
                'bank_reconciliation_id' => $matchData['bank_reconciliation_id'],
                'bank_statement_line_id' => $line->id,
                'matched_entity_type' => $matchData['matched_entity_type'] ?? 'manual_adjustment',
                'matched_entity_id' => $matchData['matched_entity_id'] ?? (string) Str::uuid7(),
                'match_type' => 'manual',
                'allocated_amount' => $amount,
                'confidence_score' => 100.00,
                'journal_entry_id' => $matchData['journal_entry_id'] ?? null,
                'reconciled_by' => $userUuid,
            ]);

            $line->update([
                'reconciled' => true,
                'reconciled_status' => 'matched',
                'reconciled_amount' => $amount,
            ]);

            $this->auditLedger->record(
                action: 'manual_reconciliation_match',
                model: $line,
                payloadBefore: null,
                payloadAfter: $line->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );
        });
    }

    public function approveReconciliation(string $reconciliationId, string $approvedByUserId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliationId, $approvedByUserId) {
            /** @var BankReconciliation $recon */
            $recon = BankReconciliation::where('id', $reconciliationId)->lockForUpdate()->firstOrFail();

            $approverUuid = Str::isUuid($approvedByUserId) ? $approvedByUserId : (string) Str::uuid7();
            $before = $recon->toArray();

            $recon->update([
                'status' => 'approved',
                'approved_by' => $approverUuid,
                'approved_at' => now(),
            ]);

            $this->auditLedger->record(
                action: 'reconciliation_approved',
                model: $recon,
                payloadBefore: $before,
                payloadAfter: $recon->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            $event = new BankReconciliationCompletedEvent(
                reconciliationId: (string) $recon->id,
                bankAccountId: (string) $recon->bank_account_id,
                periodEndDate: (string) $recon->period_end_date,
                statementBalance: (float) $recon->bank_statement_balance,
                glBookBalance: (float) $recon->gl_book_balance,
                differenceAmount: (float) $recon->difference_amount,
                tenantId: (string) $recon->tenant_id,
                companyId: (string) ($recon->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            return $recon;
        });
    }
}
