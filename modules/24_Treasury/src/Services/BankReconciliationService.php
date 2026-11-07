<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
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
    /**
     * A reference shorter than this only matches a remittance reference exactly: a short value such as "1" or "INV"
     * would otherwise be found inside the reference of unrelated payments.
     */
    public const int MIN_PARTIAL_REFERENCE_LENGTH = 8;

    /**
     * Payment lines in these states have already been matched with a bank movement.
     *
     * @var list<string>
     */
    public const array SETTLED_PAYMENT_LINE_STATUSES = ['cleared', 'reconciled'];

    /**
     * Collections still waiting for money.
     *
     * @var list<string>
     */
    public const array OPEN_COLLECTION_STATUSES = ['pending', 'partial', 'overdue'];

    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher,
        private ReceivableCollectionServiceInterface $collectionService
    ) {}

    public function importStatement(array $statementData, array $lines): BankStatement
    {
        return DB::transaction(function () use ($statementData, $lines) {
            $bankAccount = BankAccount::findOrFail($statementData['bank_account_id']);
            $statementNo = $statementData['statement_number'] ?? 'STMT-'.date('Ymd').'-'.strtoupper(Str::random(6));
            $statementDate = (string) ($statementData['statement_date'] ?? now()->toDateString());
            $contentHash = $this->statementFingerprint((string) $bankAccount->id, $lines, $statementDate);

            // The unique index on (tenant_id, content_hash) also covers soft deleted statements, so they count too.
            $alreadyImported = BankStatement::withoutGlobalScopes()
                ->where('tenant_id', $statementData['tenant_id'])
                ->where('content_hash', $contentHash)
                ->exists();

            if ($alreadyImported) {
                throw ValidationException::withMessages(['lines' => __('banking.errors.duplicate_statement')]);
            }

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
                'content_hash' => $contentHash,
                'status' => 'imported',
                'statement_date' => $statementDate,
                'format' => $statementData['format'] ?? 'CAMT_053',
                'raw_file_storage_path' => $statementData['raw_file_storage_path'] ?? 'statements/'.$statementNo.'.xml',
                'file_sha256' => $contentHash,
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

                // Pass 1: Exact reference match against PaymentLine. A Ri.Ba. batch collects money, so its lines only
                // match money coming in and are customer payments; every other batch pays out to suppliers.
                $paymentLine = null;
                $isIncoming = $line->direction === 'credit';
                $references = $this->lineReferences($line);
                if ($references !== []) {
                    $paymentLine = PaymentLine::where('tenant_id', $statement->tenant_id)
                        ->where(function ($q) use ($references): void {
                            $q->whereIn('instruction_id', $references)
                                ->orWhereIn('end_to_end_id', $references)
                                ->orWhereIn('remittance_reference', $references);

                            foreach ($references as $reference) {
                                if (mb_strlen($reference) >= self::MIN_PARTIAL_REFERENCE_LENGTH) {
                                    $q->orWhere('remittance_reference', 'like', '%'.$this->escapeLike($reference).'%');
                                }
                            }
                        })
                        ->whereHas('paymentBatch', fn ($batch) => $isIncoming
                            ? $batch->where('payment_method', 'riba')
                            : $batch->where(fn ($method) => $method->whereNull('payment_method')->orWhere('payment_method', '!=', 'riba')))
                        ->whereNotIn('status', self::SETTLED_PAYMENT_LINE_STATUSES)
                        ->orderBy('execution_date')
                        ->first();
                }

                if ($paymentLine) {
                    ReconciliationMatch::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $statement->tenant_id,
                        'bank_reconciliation_id' => $recon->id,
                        'bank_statement_line_id' => $line->id,
                        'matched_entity_type' => $isIncoming ? 'customer_payment' : 'supplier_payment',
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

                // Pass 2: an incoming amount settles an open collection only when the payer is recognised too, by the
                // invoice reference in the remittance or by the IBAN of the customer. The collection service then books
                // it, so the invoice is marked paid and the general ledger receives the payment.
                if ($isIncoming) {
                    $collectionMatch = $this->findCollectionForIncomingLine($line, $lineAmount, (string) $statement->tenant_id);

                    if ($collectionMatch !== null) {
                        $collection = $collectionMatch['collection'];

                        ReconciliationMatch::create([
                            'id' => (string) Str::uuid7(),
                            'tenant_id' => $statement->tenant_id,
                            'bank_reconciliation_id' => $recon->id,
                            'bank_statement_line_id' => $line->id,
                            'matched_entity_type' => 'customer_payment',
                            'matched_entity_id' => $collection->id,
                            'match_type' => $collectionMatch['match_type'],
                            'allocated_amount' => $lineAmount,
                            'confidence_score' => $collectionMatch['confidence'],
                            'reconciled_by' => $userUuid,
                        ]);

                        $line->update([
                            'reconciled' => true,
                            'reconciled_status' => 'matched',
                            'reconciled_amount' => $lineAmount,
                        ]);

                        $bookingDate = $line->booking_date instanceof CarbonInterface
                            ? $line->booking_date->toDateString()
                            : (string) ($line->booking_date ?? $statement->statement_date);

                        $this->collectionService->recordCollectionReceived((string) $collection->id, $lineAmount, $bookingDate);
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

    /**
     * Fingerprint of a statement built from the bank account, the period it covers and its movements. The statement
     * number stays out on purpose: when the bank file has none it defaults to a random value, and the same file
     * imported twice would get two different fingerprints.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function statementFingerprint(string $bankAccountId, array $lines, string $statementDate): string
    {
        $normalizedLines = array_map(static fn (array $line): array => [
            'booking_date' => (string) ($line['booking_date'] ?? $statementDate),
            'value_date' => (string) ($line['value_date'] ?? $statementDate),
            'amount' => number_format((float) ($line['amount'] ?? 0), 2, '.', ''),
            'fee_amount' => number_format((float) ($line['fee_amount'] ?? 0), 2, '.', ''),
            'bank_reference' => $line['bank_reference'] ?? null,
            'customer_reference' => $line['customer_reference'] ?? null,
            'counterparty_iban' => isset($line['counterparty_iban']) ? self::normalizeIban((string) $line['counterparty_iban']) : null,
            'remittance_information' => $line['remittance_information'] ?? null,
        ], array_values($lines));

        $bookingDates = array_column($normalizedLines, 'booking_date');

        return hash('sha256', (string) json_encode([
            'bank_account_id' => $bankAccountId,
            'period_start' => $bookingDates === [] ? $statementDate : min($bookingDates),
            'period_end' => $bookingDates === [] ? $statementDate : max($bookingDates),
            'lines' => $normalizedLines,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The non-empty references a bank movement carries, without duplicates.
     *
     * @return list<string>
     */
    private function lineReferences(BankStatementLine $line): array
    {
        $references = array_map(
            static fn (mixed $reference): string => trim((string) $reference),
            [$line->bank_reference, $line->customer_reference]
        );

        return array_values(array_unique(array_filter($references, static fn (string $reference): bool => $reference !== '')));
    }

    /**
     * Escapes the LIKE wildcards of a value, so it is searched literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function normalizeIban(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }

    /**
     * Finds the open collection an incoming movement pays. The amount must equal what is still open, and the payer
     * must be recognised: by the number of the collection's invoice in the references of the movement (strongest), or
     * by the IBAN of the customer. Among equal candidates the oldest due date wins.
     *
     * @return array{collection: ReceivableCollection, match_type: string, confidence: float}|null
     */
    private function findCollectionForIncomingLine(BankStatementLine $line, float $amount, string $tenantId): ?array
    {
        /** @var Collection<int, ReceivableCollection> $candidates */
        $candidates = ReceivableCollection::where('tenant_id', $tenantId)
            ->whereIn('status', self::OPEN_COLLECTION_STATUSES)
            ->whereRaw('(expected_amount - collected_amount) = ?', [round($amount, 2)])
            ->orderBy('due_date')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $searchableText = strtoupper(implode(' ', array_filter([
            (string) $line->remittance_information,
            (string) $line->bank_reference,
            (string) $line->customer_reference,
        ])));

        if ($searchableText !== '') {
            $invoiceNumbers = DB::table('invoices')
                ->whereIn('id', $candidates->pluck('invoice_id')->filter()->unique()->values()->all())
                ->pluck('invoice_number', 'id');

            foreach ($candidates as $candidate) {
                $invoiceNumber = $candidate->invoice_id !== null ? (string) ($invoiceNumbers[$candidate->invoice_id] ?? '') : '';

                if ($this->containsReference($searchableText, $invoiceNumber) || $this->containsReference($searchableText, (string) $candidate->id)) {
                    return ['collection' => $candidate, 'match_type' => 'reference_amount', 'confidence' => 98.00];
                }
            }
        }

        $payerIban = $line->counterparty_iban !== null ? self::normalizeIban((string) $line->counterparty_iban) : '';

        if ($payerIban === '') {
            return null;
        }

        $customerIbans = DB::table('contacts')
            ->whereIn('id', $candidates->pluck('customer_id')->filter()->unique()->values()->all())
            ->get(['id', 'iban', 'qr_iban'])
            ->mapWithKeys(static fn (object $contact): array => [
                (string) $contact->id => array_map(
                    static fn (?string $iban): string => self::normalizeIban((string) $iban),
                    [$contact->iban, $contact->qr_iban]
                ),
            ]);

        foreach ($candidates as $candidate) {
            if (in_array($payerIban, $customerIbans[(string) $candidate->customer_id] ?? [], true)) {
                return ['collection' => $candidate, 'match_type' => 'iban_amount', 'confidence' => 90.00];
            }
        }

        return null;
    }

    /**
     * Whether the text contains the reference as a whole token, so "FT-12" is not found inside "FT-123".
     */
    private function containsReference(string $searchableText, string $reference): bool
    {
        $reference = strtoupper(trim($reference));

        if (mb_strlen($reference) < 3) {
            return false;
        }

        return preg_match('/(?<![A-Z0-9])'.preg_quote($reference, '/').'(?![A-Z0-9])/u', $searchableText) === 1;
    }
}
