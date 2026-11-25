<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Sales\RecordPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Banking\BankReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\Treasury\Models\ReconciliationProposal;
use Modules\Treasury\Services\TreasuryLedgerAccounts;
use Modules\Treasury\Services\TreasuryLedgerPoster;

/**
 * Stores the proposals of the smart engine and turns an accepted one into bookings, atomically:
 *
 * - same currency: the existing reconciliation service settles the document (payment, bank fee as a separate bank
 *   charge posting, rounding) and the line is stamped with the rule that found it;
 * - fee tolerance: the shortfall is first recorded as the bank fee of the line, then settled as above;
 * - foreign currency: the document is settled in its own currency and the exchange difference is posted to the
 *   realised FX gain / loss accounts (`banking.ledger_defaults.fx_realised_gain_account_code` / `..._loss_...`).
 *
 * Undoing an application reverses the payments and every posting, so a reconciliation can always be taken back.
 */
final readonly class ReconciliationProposalService
{
    public const string FX_SOURCE = 'bank_fx_difference';

    public function __construct(
        private SmartReconciliationEngine $engine,
        private BankReconciliationService $banking,
        private RecordPayment $payments,
        private RecordSupplierPayment $supplierPayments,
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $accounts,
        private LedgerAccountResolver $resolver,
    ) {}

    /**
     * Computes and stores the proposals of every open line of a statement; unique, confident proposals are applied
     * when `$autoApply` is set. Returns the counters of the run.
     *
     * @return array{proposed: int, applied: int, lines_without_proposal: int}
     */
    public function run(BankStatement $statement, bool $autoApply): array
    {
        $settings = SmartSettings::forTenant((string) $statement->tenant_id);
        $this->engine->reset();
        $proposed = 0;
        $applied = 0;
        $none = 0;

        foreach ($statement->lines()->where('reconciled', false)->get() as $line) {
            $proposals = $this->refresh($line, $settings);

            if ($proposals->isEmpty()) {
                $none++;

                continue;
            }

            $proposed += $proposals->count();

            if ($autoApply && $this->isUnambiguous($proposals, $settings)) {
                try {
                    $this->apply($proposals->first(), null);
                    $applied++;
                } catch (ValidationException) {
                    // The line stays open for the assisted workflow.
                }
            }
        }

        $this->banking->refreshStatus($statement);

        return ['proposed' => $proposed, 'applied' => $applied, 'lines_without_proposal' => $none];
    }

    /**
     * Recomputes the pending proposals of one line (the old pending ones are superseded).
     *
     * @return Collection<int, ReconciliationProposal>
     */
    public function refresh(BankStatementLine $line, ?SmartSettings $settings = null): Collection
    {
        $settings ??= SmartSettings::forTenant((string) $line->tenant_id);

        return DB::transaction(function () use ($line, $settings): Collection {
            ReconciliationProposal::query()
                ->where('bank_statement_line_id', $line->id)
                ->where('status', ReconciliationProposal::PENDING)
                ->update(['status' => ReconciliationProposal::SUPERSEDED]);

            foreach ($this->engine->propose($line, $settings) as $candidate) {
                $assessment = $candidate->assessment;
                $proposal = ReconciliationProposal::withTrashed()->firstOrNew([
                    'bank_statement_line_id' => $line->id,
                    'document_type' => $candidate->documentType,
                    'document_id' => $candidate->document->id,
                ]);

                if ($proposal->exists && $proposal->status === ReconciliationProposal::REJECTED) {
                    continue;
                }

                $proposal->fill([
                    'tenant_id' => $line->tenant_id,
                    'rule' => $candidate->rule,
                    'confidence' => $candidate->confidence,
                    'matched_amount' => $assessment->matchedAmount,
                    'fee_amount' => $assessment->feeAmount,
                    'fx_difference' => $assessment->fxDifference,
                    'residual_amount' => $assessment->residualAmount,
                    'currency' => (string) $candidate->document->currency,
                    'status' => ReconciliationProposal::PENDING,
                    'evidence' => [
                        'kind' => $assessment->kind,
                        'reasons' => $candidate->reasons,
                        'rate' => $assessment->rate,
                        'line_currency' => $line->currency,
                        'line_amount' => (string) $line->amount,
                        'line_fee' => (string) $line->fee_amount,
                        'document_number' => (string) $candidate->document->invoice_number,
                    ],
                ]);
                $proposal->deleted_at = null;
                $proposal->save();
            }

            return ReconciliationProposal::query()
                ->where('bank_statement_line_id', $line->id)
                ->where('status', ReconciliationProposal::PENDING)
                ->orderByDesc('confidence')
                ->get();
        });
    }

    /**
     * 1-click assisted matching: books the proposal.
     *
     * @throws ValidationException when the proposal or the line is no longer open or the posting is refused
     */
    public function apply(ReconciliationProposal $proposal, ?string $userId): BankStatementLine
    {
        return DB::transaction(function () use ($proposal, $userId): BankStatementLine {
            $proposal = ReconciliationProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($proposal->status !== ReconciliationProposal::PENDING) {
                throw ValidationException::withMessages(['proposal' => __('treasury_flows.reconciliation.proposal_not_pending')]);
            }

            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($proposal->bank_statement_line_id);

            if ($line->reconciled) {
                $proposal->update(['status' => ReconciliationProposal::SUPERSEDED]);

                throw ValidationException::withMessages(['line' => __('banking.errors.line_already_reconciled')]);
            }

            $document = $proposal->document_type === ReconciliationProposal::DOCUMENT_PASSIVE_INVOICE
                ? PassiveInvoice::query()->findOrFail($proposal->document_id)
                : Invoice::query()->findOrFail($proposal->document_id);
            $kind = (string) ($proposal->evidence['kind'] ?? Assessment::EXACT);
            $settings = SmartSettings::forTenant((string) $line->tenant_id);

            $line = $kind === Assessment::FX
                ? $this->applyForeign($line, $document, $proposal)
                : $this->applySameCurrency($line, $document, $proposal, $kind, $settings);

            $line->update(['reconciliation_notes' => __('treasury_flows.reconciliation.note', ['rule' => $proposal->rule, 'confidence' => $proposal->confidence])]);
            $proposal->update(['status' => ReconciliationProposal::APPLIED, 'applied_at' => now(), 'applied_by' => $userId]);
            ReconciliationProposal::query()
                ->where('bank_statement_line_id', $line->id)
                ->where('status', ReconciliationProposal::PENDING)
                ->update(['status' => ReconciliationProposal::SUPERSEDED]);

            return $line->refresh();
        });
    }

    public function reject(ReconciliationProposal $proposal): ReconciliationProposal
    {
        if ($proposal->status !== ReconciliationProposal::PENDING) {
            throw ValidationException::withMessages(['proposal' => __('treasury_flows.reconciliation.proposal_not_pending')]);
        }

        $proposal->update(['status' => ReconciliationProposal::REJECTED]);

        return $proposal->refresh();
    }

    /**
     * Takes back a reconciliation: payments and postings (including the exchange difference) are reversed and the
     * applied proposal goes back to pending, so the line can be matched again.
     */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        return DB::transaction(function () use ($line): BankStatementLine {
            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->poster->reverse((string) $line->tenant_id, self::FX_SOURCE, (string) $line->id, CarbonImmutable::parse($line->booking_date)->toDateString(), 'Reconciliation undone', false);
            $line = $this->banking->unmatch($line);
            ReconciliationProposal::query()
                ->where('bank_statement_line_id', $line->id)
                ->where('status', ReconciliationProposal::APPLIED)
                ->update(['status' => ReconciliationProposal::PENDING, 'applied_at' => null, 'applied_by' => null]);

            return $line;
        });
    }

    /**
     * A single best proposal at or above the auto-apply confidence, with no rival within five points.
     *
     * @param  Collection<int, ReconciliationProposal>  $proposals
     */
    private function isUnambiguous(Collection $proposals, SmartSettings $settings): bool
    {
        $top = $proposals->first();
        $second = $proposals->skip(1)->first();

        return $top->confidence >= $settings->autoApplyConfidence
            && (string) ($top->evidence['kind'] ?? '') !== Assessment::PARTIAL
            && ($second === null || $top->confidence - $second->confidence >= 5);
    }

    private function applySameCurrency(BankStatementLine $line, Invoice|PassiveInvoice $document, ReconciliationProposal $proposal, string $kind, SmartSettings $settings): BankStatementLine
    {
        if ($kind === Assessment::FEE_TOLERANCE) {
            $line->update(['fee_amount' => $proposal->fee_amount]);
        }

        $line = $this->banking->manualMatch($line->refresh(), $document, $kind === Assessment::PARTIAL ? 'partial' : 'none', $settings->base);

        if ($kind !== Assessment::PARTIAL) {
            $line->update(['reconciliation_type' => match ($proposal->rule) {
                'structured_reference' => BankStatementLine::QR_REFERENCE,
                'amount_counterparty' => BankStatementLine::EXACT,
                default => BankStatementLine::FUZZY_TOLERANCE,
            }]);
        }

        return $line;
    }

    /**
     * Settles the document in its own currency (net amount and fee) and posts the exchange difference between what the
     * bank moved and what was booked.
     */
    private function applyForeign(BankStatementLine $line, Invoice|PassiveInvoice $document, ReconciliationProposal $proposal): BankStatementLine
    {
        $statement = BankStatement::query()->findOrFail($line->bank_statement_id);
        $due = (string) $proposal->matched_amount;
        $fee = (string) $proposal->fee_amount;
        $netPayment = $line->isCredit() ? bcsub($due, $fee, 2) : $due;
        $common = ['paid_at' => CarbonImmutable::parse($line->booking_date)->toDateString(), 'reference' => $line->reference, 'bank_statement_line_id' => $line->id];

        $pay = fn (array $data) => $document instanceof PassiveInvoice
            ? $this->supplierPayments->execute($document, $data)
            : $this->payments->execute($document, $data);

        if (bccomp($netPayment, '0', 2) > 0) {
            $pay($common + ['amount' => $netPayment, 'type' => 'payment', 'notes' => __('banking.payment_notes.payment')]);
        }

        if (bccomp($fee, '0', 2) > 0) {
            $pay($common + ['amount' => $fee, 'type' => 'bank_fee', 'notes' => __('banking.payment_notes.bank_fee')]);
        }

        $line->update([
            'reconciled' => true,
            'matched_document_id' => $document->id,
            'reconciliation_type' => BankStatementLine::FUZZY_TOLERANCE,
            'reconciliation_category' => BankStatementLine::CATEGORY_INVOICE,
        ]);

        $this->postFxDifference($line, $statement, (string) $proposal->fx_difference, (string) $proposal->currency);
        $this->banking->refreshStatus($statement);

        return $line;
    }

    /**
     * Gain: Dr bank / Cr realised FX gain. Loss: Dr realised FX loss / Cr bank.
     */
    private function postFxDifference(BankStatementLine $line, BankStatement $statement, string $gain, string $currency): void
    {
        if (bccomp($gain, '0', 2) === 0) {
            return;
        }

        $tenantId = (string) $line->tenant_id;
        $isGain = bccomp($gain, '0', 2) > 0;
        $amount = ltrim($gain, '-');
        $bank = $this->resolver->bank($tenantId, (string) $statement->currency, (string) $statement->account_iban);
        $counter = $this->accounts->configured($tenantId, $isGain ? 'fx_realised_gain_account_code' : 'fx_realised_loss_account_code', 'ledger_account_code');
        $label = __('treasury_flows.reconciliation.fx_label', ['reference' => (string) ($line->reference ?: $line->line_number)]);
        $row = static fn ($account, string $debit, string $credit): array => ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $label, 'currency' => $currency];

        $this->poster->post(
            $tenantId,
            self::FX_SOURCE,
            (string) $line->id,
            CarbonImmutable::parse($line->booking_date)->toDateString(),
            (string) ($line->reference ?: $statement->account_iban),
            $label,
            $isGain ? [$row($bank, $amount, '0'), $row($counter, '0', $amount)] : [$row($counter, $amount, '0'), $row($bank, '0', $amount)],
            false,
        );
    }
}
