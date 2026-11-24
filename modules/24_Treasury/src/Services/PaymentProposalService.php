<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PaymentTerm;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Events\PaymentProposalApprovedEvent;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\PaymentProposal;
use Modules\Treasury\Models\PaymentProposalLine;

/**
 * Supplier payment proposals.
 *
 * The proposal selects the open supplier invoices of the bank account currency, approved for payment and not already
 * in another open proposal or payment batch, that are due by `due_until`, or whose early-payment discount (payment
 * term of the supplier: `discount_percent` within `discount_days` of the invoice date) is still available on the
 * execution date: those are paid net of the discount. Invoices of suppliers without an IBAN are listed as excluded.
 *
 * Approval applies segregation of duties: the author of a proposal cannot approve it. The approval turns the proposal
 * into a payment batch of the payment factory, created by the author and approved by the approver, ready for the
 * existing pain.001 export (SEPA or Swiss). When the bank confirms the payments, the discount taken closes the rest of
 * each invoice through RecordSupplierPayment (an adjustment, posted by the payment ledger).
 */
final readonly class PaymentProposalService
{
    /** @var array<string, string> proposal format => payment batch format */
    public const array Formats = ['sepa_pain_001_it' => 'sepa_pain_001_it', 'iso_pain_001_ch' => 'iso_pain_001_ch'];

    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory,
        private RecordSupplierPayment $supplierPayments,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Builds a draft proposal.
     *
     * @param  array{bank_account_id: string, execution_date: string, due_until: string, include_discounts?: bool, format: string, supplier_ids?: list<string>|null}  $data
     * @return array{proposal: PaymentProposal, excluded: list<array{invoice_id: string, invoice_number: string, reason: string}>}
     *
     * @throws TreasuryOperationException when nothing can be proposed
     */
    public function propose(string $tenantId, array $data, string $userId): array
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): array {
            $account = BankAccount::query()->whereKey($data['bank_account_id'])->where('is_active', true)->firstOrFail();
            $execution = CarbonImmutable::parse($data['execution_date'])->startOfDay();
            $dueUntil = CarbonImmutable::parse($data['due_until'])->startOfDay();
            $withDiscounts = (bool) ($data['include_discounts'] ?? true);
            $excluded = [];
            $selected = [];

            foreach ($this->candidates($tenantId, (string) $account->currency, $data['supplier_ids'] ?? null) as $invoice) {
                $line = $this->selection($invoice, $execution, $dueUntil, $withDiscounts);

                if ($line === null) {
                    continue;
                }

                if ($this->iban($invoice) === null) {
                    $excluded[] = ['invoice_id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->invoice_number, 'reason' => 'supplier_without_iban'];

                    continue;
                }

                $selected[] = $line;
            }

            if ($selected === []) {
                throw new TreasuryOperationException('No open supplier invoice matches the proposal criteria.', 'due_until');
            }

            $proposal = PaymentProposal::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $account->company_id,
                'proposal_number' => 'PP-'.$execution->format('Ymd').'-'.strtoupper(Str::random(6)),
                'bank_account_id' => $account->id,
                'status' => PaymentProposal::Draft,
                'format' => $data['format'],
                'execution_date' => $execution->toDateString(),
                'due_until' => $dueUntil->toDateString(),
                'include_discounts' => $withDiscounts,
                'currency' => $account->currency,
                'total_amount' => array_reduce($selected, static fn (string $sum, array $line): string => bcadd($sum, $line['amount'], 2), '0'),
                'total_discount' => array_reduce($selected, static fn (string $sum, array $line): string => bcadd($sum, $line['discount_amount'], 2), '0'),
                'item_count' => count($selected),
                'created_by' => $userId,
            ]);

            foreach ($selected as $line) {
                PaymentProposalLine::query()->create(['tenant_id' => $tenantId, 'payment_proposal_id' => $proposal->id] + $line);
            }

            return ['proposal' => $proposal->load('lines'), 'excluded' => $excluded];
        });
    }

    /**
     * Approves a draft proposal and turns it into an approved payment batch.
     *
     * @throws TreasuryOperationException when the approver is the author or an invoice changed since the proposal
     */
    public function approve(string $proposalId, string $approverId, ?string $ipAddress): PaymentProposal
    {
        return DB::transaction(function () use ($proposalId, $approverId, $ipAddress): PaymentProposal {
            $proposal = PaymentProposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($proposal);

            if ((string) $proposal->created_by === $approverId) {
                throw new TreasuryOperationException('Segregation of duties: a payment proposal must be approved by someone other than its author.', 'approver');
            }

            $lines = $proposal->lines()->get();
            $invoices = PassiveInvoice::query()->with('supplier')->whereIn('id', $lines->pluck('passive_invoice_id'))->lockForUpdate()->get()->keyBy('id');
            $batchLines = $lines->map(fn (PaymentProposalLine $line): array => $this->batchLine($line, $invoices->get($line->passive_invoice_id)))->all();

            $batch = $this->paymentFactory->createBatch([
                'tenant_id' => $proposal->tenant_id,
                'company_id' => $proposal->company_id,
                'bank_account_id' => $proposal->bank_account_id,
                'batch_reference' => $proposal->proposal_number,
                'payment_method' => $proposal->format === 'iso_pain_001_ch' ? 'swiss_qr_pain001' : 'sepa_credit_transfer',
                'format' => self::Formats[$proposal->format],
                'execution_date' => $proposal->execution_date->toDateString(),
                'currency' => $proposal->currency,
            ], $batchLines, (string) $proposal->created_by);

            $this->paymentFactory->submitForApproval((string) $batch->id, (string) $proposal->created_by);
            $this->paymentFactory->approveBatch((string) $batch->id, $approverId, $ipAddress);

            $proposal->update([
                'status' => PaymentProposal::Converted,
                'approved_by' => $approverId,
                'approved_at' => now(),
                'payment_batch_id' => $batch->id,
            ]);

            $this->dispatcher->publishAndDispatch(new PaymentProposalApprovedEvent(
                proposalId: (string) $proposal->id,
                paymentBatchId: (string) $batch->id,
                approvedBy: $approverId,
                totalAmount: (string) $proposal->total_amount,
                currency: (string) $proposal->currency,
                itemCount: (int) $proposal->item_count,
                tenantId: (string) $proposal->tenant_id,
                companyId: (string) ($proposal->company_id ?? ''),
            ));

            return $proposal->refresh()->load('lines');
        });
    }

    /**
     * Rejects a draft proposal; its invoices become available to the next proposal.
     */
    public function reject(string $proposalId, string $reason): PaymentProposal
    {
        return DB::transaction(function () use ($proposalId, $reason): PaymentProposal {
            $proposal = PaymentProposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($proposal);
            $proposal->update(['status' => PaymentProposal::Rejected, 'rejection_reason' => $reason]);

            return $proposal->refresh()->load('lines');
        });
    }

    /**
     * Once the bank executed the payments of a batch born from a proposal, the early-payment discount of each paid
     * invoice closes its rest, once.
     */
    public function recordDiscountsTaken(PaymentExecutedEvent $event): void
    {
        $proposal = PaymentProposal::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->where('payment_batch_id', $event->paymentBatchId)->first();

        if ($proposal === null) {
            return;
        }

        $paidInvoices = PaymentLine::withoutGlobalScopes()->whereIn('id', $event->executedLineIds)->whereNotNull('invoice_id')->pluck('invoice_id');
        $lines = PaymentProposalLine::withoutGlobalScopes()
            ->where('payment_proposal_id', $proposal->id)
            ->whereIn('passive_invoice_id', $paidInvoices)
            ->where('discount_amount', '>', 0)
            ->whereNull('discount_payment_id')
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $invoice = PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->findOrFail($line->passive_invoice_id);
            $payment = $this->supplierPayments->execute($invoice, [
                'amount' => (string) $line->discount_amount,
                'paid_at' => $event->valueDate ?? now()->toDateString(),
                'type' => 'adjustment',
                'reference' => $proposal->proposal_number,
                'notes' => 'Early payment discount',
            ]);
            $line->update(['discount_payment_id' => $payment->id]);
        }
    }

    /**
     * Open supplier invoices that may be proposed.
     *
     * @param  list<string>|null  $supplierIds
     * @return Collection<int, PassiveInvoice>
     */
    private function candidates(string $tenantId, string $currency, ?array $supplierIds): Collection
    {
        $busy = PaymentProposalLine::query()
            ->join('treasury_payment_proposals as proposal', 'proposal.id', '=', 'treasury_payment_proposal_lines.payment_proposal_id')
            ->where('proposal.status', PaymentProposal::Draft)
            ->whereNull('proposal.deleted_at')
            ->pluck('treasury_payment_proposal_lines.passive_invoice_id')
            ->merge(PaymentLine::query()
                ->join('payment_batches as batch', 'batch.id', '=', 'payment_lines.payment_batch_id')
                ->where('payment_lines.status', 'pending')
                ->whereNotIn('batch.status', ['rejected', 'cancelled', 'canceled'])
                ->whereNotNull('payment_lines.invoice_id')
                ->pluck('payment_lines.invoice_id'))
            ->unique()
            ->all();

        return PassiveInvoice::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->where('document_type', '!=', 'TD04')
            ->where('payment_status', '!=', 'paid')
            ->where(fn ($query) => $query->whereNull('approval_status')->orWhereNotIn('approval_status', PassiveInvoice::PaymentBlockingStatuses))
            ->when($supplierIds !== null && $supplierIds !== [], fn ($query) => $query->whereIn('supplier_id', $supplierIds))
            ->whereNotIn('id', $busy)
            ->orderBy('due_date')
            ->orderBy('invoice_number')
            ->get();
    }

    /**
     * The proposal line of an invoice, null when it is neither due nor eligible for its discount.
     *
     * @return array{passive_invoice_id: string, supplier_id: string, selection_reason: string, due_date: ?string, discount_deadline: ?string, open_amount: string, discount_amount: string, amount: string}|null
     */
    private function selection(PassiveInvoice $invoice, CarbonImmutable $execution, CarbonImmutable $dueUntil, bool $withDiscounts): ?array
    {
        $open = bcsub((string) $invoice->amountDue(), bcadd((string) ($invoice->total_paid ?? '0'), '0', 2), 2);

        if (bccomp($open, '0', 2) !== 1) {
            return null;
        }

        $line = [
            'passive_invoice_id' => (string) $invoice->id,
            'supplier_id' => (string) $invoice->supplier_id,
            'selection_reason' => PaymentProposalLine::ReasonDue,
            'due_date' => $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->toDateString() : null,
            'discount_deadline' => null,
            'open_amount' => $open,
            'discount_amount' => '0.00',
            'amount' => $open,
        ];
        $term = $withDiscounts ? $this->paymentTerm($invoice->supplier) : null;
        $deadline = $term === null ? null : CarbonImmutable::parse($invoice->date)->addDays((int) $term->discount_days);

        if ($term !== null && $deadline !== null && $execution->lessThanOrEqualTo($deadline) && bccomp((string) ($invoice->total_paid ?? '0'), '0', 2) === 0) {
            $discount = Decimal::round2(Decimal::div(Decimal::mul($open, (string) $term->discount_percent), '100'));

            return ['selection_reason' => PaymentProposalLine::ReasonDiscount, 'discount_deadline' => $deadline->toDateString(), 'discount_amount' => $discount, 'amount' => bcsub($open, $discount, 2)] + $line;
        }

        return $invoice->due_date && CarbonImmutable::parse($invoice->due_date)->lessThanOrEqualTo($dueUntil) ? $line : null;
    }

    private function paymentTerm(?Contact $supplier): ?PaymentTerm
    {
        $termId = $supplier?->getAttribute('payment_term_id');
        $term = $termId === null ? null : PaymentTerm::query()->find($termId);

        return $term !== null && bccomp((string) ($term->discount_percent ?? '0'), '0', 2) === 1 && (int) $term->discount_days > 0 ? $term : null;
    }

    /**
     * The IBAN to pay: the one of the QR-bill of the invoice, else the IBAN (or QR-IBAN) of the supplier.
     */
    private function iban(PassiveInvoice $invoice): ?string
    {
        $qr = $invoice->qr_raw_payload !== null ? preg_split('/\r\n|\r|\n/', trim((string) $invoice->qr_raw_payload)) : [];
        $iban = ($qr[3] ?? '') ?: ($invoice->supplier?->iban ?: $invoice->supplier?->qr_iban);

        return $iban === null || $iban === '' ? null : str_replace(' ', '', (string) $iban);
    }

    /**
     * The payment batch line of a proposal line, after checking the invoice can still take that payment.
     *
     * @return array<string, mixed>
     *
     * @throws TreasuryOperationException
     */
    private function batchLine(PaymentProposalLine $line, ?PassiveInvoice $invoice): array
    {
        $open = $invoice === null ? '0' : bcsub((string) $invoice->amountDue(), bcadd((string) ($invoice->total_paid ?? '0'), '0', 2), 2);

        if ($invoice === null || $invoice->isPaymentBlocked() || bccomp($open, (string) $line->open_amount, 2) !== 0 || $this->iban($invoice) === null) {
            throw new TreasuryOperationException('An invoice of the proposal changed since it was prepared: reject it and prepare a new one.', 'proposal');
        }

        $qr = $invoice->qr_raw_payload !== null ? preg_split('/\r\n|\r|\n/', trim((string) $invoice->qr_raw_payload)) : [];
        $supplier = $invoice->supplier;

        return [
            'recipient_type' => 'supplier',
            'recipient_id' => $invoice->supplier_id,
            'recipient_name' => (string) ($supplier?->company_name ?: trim($supplier?->first_name.' '.$supplier?->last_name)),
            'recipient_iban' => $this->iban($invoice),
            'amount' => (string) $line->amount,
            'remittance_reference' => "Invoice {$invoice->invoice_number}",
            'structured_creditor_ref' => ($qr[28] ?? '') !== '' ? $qr[28] : null,
            'invoice_id' => $invoice->id,
        ];
    }

    /**
     * @throws TreasuryOperationException
     */
    private function assertDraft(PaymentProposal $proposal): void
    {
        if ($proposal->status !== PaymentProposal::Draft) {
            throw new TreasuryOperationException("Payment proposal {$proposal->proposal_number} is {$proposal->status}, not a draft.", 'proposal');
        }
    }
}
