<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Sales\RecordPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconciles the lines of a bank statement, so that the whole statement can be closed.
 *
 * Incoming lines (credits) are matched with open customer invoices:
 *  1. the QR / creditor reference equals the reference of an invoice QR-bill;
 *  2. the invoice number appears in the description or reference;
 *  3. the counterpart (IBAN or VAT / fiscal code) is a customer with exactly one invoice whose balance matches.
 *
 * Outgoing lines (debits) are matched with open supplier invoices (received invoices):
 *  1. the supplier invoice number appears in the description or reference;
 *  2. the counterpart is a supplier with exactly one open invoice whose balance matches;
 * and otherwise, when `banking.ledger_defaults.auto_categorize_standard_codes` is on, recognised as an outflow
 * without an invoice: F24 / taxes, payroll or bank charges, which get the default ledger account of the tenant.
 *
 * Amounts: for a customer invoice the effective amount is `amount + fee_amount` (the bank withheld the fee from what the
 * customer paid); for a supplier invoice it is the `amount` alone, because the fee of a transfer is a bank cost of the
 * company and does not pay the supplier. Against the balance, a difference within the tolerance is closed with a rounding adjustment, a bigger shortfall is booked as a partial payment (when allowed) and
 * an excess is left for manual handling. The net amount, the fee and the adjustment are booked as separate payments
 * tagged with the line, so they can be undone.
 */
class BankReconciliationService
{
    public const MODE_COMPLETE = 'complete';

    public const MODE_PARTIAL = 'partial';

    public const MODE_WRITE_OFF = 'write_off';

    /**
     * ABI causes (leading zeros ignored) and keywords recognised on outflows without an invoice, in priority order.
     *
     * @var array<string, array{abi: list<string>, keywords: list<string>}>
     */
    private const STANDARD_OUTFLOWS = [
        BankStatementLine::CATEGORY_TAX_PAYMENT => [
            'abi' => ['07'],
            'keywords' => ['/DELEGA\s+F24/', '/(?<![A-Z0-9])F24(?![A-Z0-9])/', '/ERARIO/'],
        ],
        BankStatementLine::CATEGORY_PAYROLL => [
            'abi' => ['27', 'SALA'],
            'keywords' => ['/EMOLUMENTI/', '/STIPEND[IO]/', '/RETRIBUZION[EI]/'],
        ],
        BankStatementLine::CATEGORY_BANK_EXPENSE => [
            'abi' => ['16', '17', '34'],
            'keywords' => ['/IMPOSTA\s+(DI\s+)?BOLLO/', '/SPESE\s+TENUTA\s+CONTO/', '/COMPETENZE/'],
        ],
    ];

    /**
     * Open document numbers loaded once per statement run, per direction (document id => number).
     *
     * @var array<string, array<string, string>>
     */
    private array $openNumbers = [];

    public function __construct(
        private readonly RecordPayment $payments,
        private readonly RecordSupplierPayment $supplierPayments,
    ) {}

    /**
     * Reconcile every line of the statement that is not reconciled yet: incoming, outgoing commercial and outgoing
     * non-commercial lines.
     *
     * @return array{reconciled: int, unmatched: int}
     */
    public function reconcileStatement(BankStatement $statement): array
    {
        $settings = ReconciliationSettings::forTenant($statement->tenant_id);
        $this->openNumbers = [];
        $reconciled = 0;
        $unmatched = 0;

        foreach ($statement->lines()->where('reconciled', false)->get() as $line) {
            $this->reconcileLine($line, $statement, $settings) ? $reconciled++ : $unmatched++;
        }

        $this->refreshStatus($statement);

        return ['reconciled' => $reconciled, 'unmatched' => $unmatched];
    }

    /**
     * Try to reconcile one line automatically.
     */
    public function reconcileLine(BankStatementLine $line, BankStatement $statement, ReconciliationSettings $settings): bool
    {
        if ($line->reconciled) {
            return false;
        }

        return $line->isCredit()
            ? $this->reconcileCredit($line, $statement, $settings)
            : $this->reconcileDebit($line, $statement, $settings);
    }

    /**
     * Match a line with a chosen document: a customer invoice for an incoming line, a received supplier invoice for an
     * outgoing one. `$differenceHandling` says what to do when the amounts differ: `none` (only differences within the
     * tolerance), `write_off` (the shortfall is forgiven) or `partial` (the invoice stays open for the rest).
     *
     * @throws ValidationException
     */
    public function manualMatch(BankStatementLine $line, Invoice|PassiveInvoice $document, string $differenceHandling, ReconciliationSettings $settings): BankStatementLine
    {
        return DB::transaction(function () use ($line, $document, $differenceHandling, $settings): BankStatementLine {
            $line = BankStatementLine::lockForUpdate()->findOrFail($line->id);
            $document = $document instanceof PassiveInvoice
                ? PassiveInvoice::lockForUpdate()->findOrFail($document->id)
                : Invoice::lockForUpdate()->findOrFail($document->id);
            $statement = BankStatement::findOrFail($line->bank_statement_id);

            if ($line->reconciled) {
                throw ValidationException::withMessages(['line' => __('banking.errors.line_already_reconciled')]);
            }

            if ($line->isCredit() === ($document instanceof PassiveInvoice)) {
                throw ValidationException::withMessages(['invoice_id' => __('banking.errors.document_direction_mismatch')]);
            }

            $due = $this->dueAmount($document);

            if (! $this->isPayable($document) || $due <= 0 || $document->currency !== $statement->currency) {
                throw ValidationException::withMessages(['invoice_id' => __('banking.errors.invoice_not_payable')]);
            }

            $delta = $this->delta($line, $due);

            if ($delta < -$settings->tolerance) {
                throw ValidationException::withMessages(['invoice_id' => __('banking.errors.overpayment')]);
            }

            $mode = match (true) {
                abs($delta) <= $settings->tolerance => self::MODE_COMPLETE,
                $differenceHandling === 'write_off' => self::MODE_WRITE_OFF,
                $differenceHandling === 'partial' => self::MODE_PARTIAL,
                default => throw ValidationException::withMessages(['difference_handling' => __('banking.errors.difference_not_accepted', ['difference' => number_format($delta, 2, '.', '')])]),
            };

            $this->book($line, $document, $mode, BankStatementLine::MANUAL);
            $this->refreshStatus($statement);

            return $line->refresh();
        });
    }

    /**
     * Book a line that has no invoice (taxes, payroll, bank expenses, internal transfers, other expenses). When no
     * ledger account is given, the tenant default of the category is used.
     *
     * @throws ValidationException
     */
    public function categorize(BankStatementLine $line, string $category, ?string $ledgerAccountCode, ?string $notes, ReconciliationSettings $settings): BankStatementLine
    {
        return DB::transaction(function () use ($line, $category, $ledgerAccountCode, $notes, $settings): BankStatementLine {
            $line = BankStatementLine::lockForUpdate()->findOrFail($line->id);

            if ($line->reconciled) {
                throw ValidationException::withMessages(['line' => __('banking.errors.line_already_reconciled')]);
            }

            if ($line->isCredit() && $category !== BankStatementLine::CATEGORY_INTERNAL_TRANSFER) {
                throw ValidationException::withMessages(['category' => __('banking.errors.category_not_for_credit')]);
            }

            $line->update([
                'reconciled' => true,
                'reconciliation_type' => BankStatementLine::MANUAL_CATEGORIZATION,
                'reconciliation_category' => $category,
                'ledger_account_code' => $ledgerAccountCode ?: $settings->accountFor($category),
                'reconciliation_notes' => $notes,
            ]);

            $this->refreshStatus(BankStatement::findOrFail($line->bank_statement_id));

            return $line->refresh();
        });
    }

    /**
     * Undo the reconciliation of a line: the payments it created are removed and the invoice balance is restored, or
     * the categorisation is cleared.
     *
     * @throws ValidationException
     */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        return DB::transaction(function () use ($line): BankStatementLine {
            $line = BankStatementLine::lockForUpdate()->findOrFail($line->id);

            if (! $line->reconciled) {
                throw ValidationException::withMessages(['line' => __('banking.errors.line_not_reconciled')]);
            }

            if ($line->matched_document_id !== null) {
                $this->revertPayments($line);
            }

            $line->update([
                'reconciled' => false,
                'matched_document_id' => null,
                'reconciliation_type' => null,
                'reconciliation_category' => null,
                'ledger_account_code' => null,
                'reconciliation_notes' => null,
            ]);
            $this->refreshStatus(BankStatement::findOrFail($line->bank_statement_id));

            return $line->refresh();
        });
    }

    /**
     * `reconciled` when every line is reconciled, `partially_reconciled` when some are, otherwise `draft`.
     */
    public function refreshStatus(BankStatement $statement): BankStatement
    {
        $lines = BankStatementLine::query()->where('bank_statement_id', $statement->id);
        $total = (clone $lines)->count();
        $done = (clone $lines)->where('reconciled', true)->count();

        $statement->update(['status' => match (true) {
            $total > 0 && $done === $total => BankStatement::RECONCILED,
            $done > 0 => BankStatement::PARTIALLY_RECONCILED,
            default => BankStatement::DRAFT,
        }]);

        return $statement;
    }

    private function reconcileCredit(BankStatementLine $line, BankStatement $statement, ReconciliationSettings $settings): bool
    {
        $byReference = $this->findByQrReference($line, $statement);

        if ($byReference !== null) {
            return $this->settle($line, $byReference, $statement, $settings, true, BankStatementLine::QR_REFERENCE);
        }

        $byNumber = $this->findByDocumentNumber($line, $statement, true);

        if ($byNumber !== null) {
            return $this->settle($line, $byNumber, $statement, $settings, true);
        }

        $byCounterpart = $this->findByCounterpart($line, $statement, $settings, true);

        return $byCounterpart !== null && $this->settle($line, $byCounterpart, $statement, $settings, false);
    }

    private function reconcileDebit(BankStatementLine $line, BankStatement $statement, ReconciliationSettings $settings): bool
    {
        $byNumber = $this->findByDocumentNumber($line, $statement, false);

        if ($byNumber !== null) {
            return $this->settle($line, $byNumber, $statement, $settings, true);
        }

        $bySupplier = $this->findByCounterpart($line, $statement, $settings, false);

        if ($bySupplier !== null) {
            return $this->settle($line, $bySupplier, $statement, $settings, false);
        }

        return $settings->autoCategorize && $this->categorizeAutomatically($line, $settings);
    }

    /**
     * Recognise F24 / tax payments, payroll and bank charges from the transaction code or the description.
     */
    private function categorizeAutomatically(BankStatementLine $line, ReconciliationSettings $settings): bool
    {
        $category = $this->standardCategory($line);

        if ($category === null) {
            return false;
        }

        $line->update([
            'reconciled' => true,
            'reconciliation_type' => BankStatementLine::AUTO_CATEGORIZATION,
            'reconciliation_category' => $category,
            'ledger_account_code' => $settings->accountFor($category),
        ]);

        return true;
    }

    private function standardCategory(BankStatementLine $line): ?string
    {
        $code = $this->normalizeCode($line->abi_code);
        $text = strtoupper((string) preg_replace('/\s+/', ' ', (string) $line->description));

        foreach (self::STANDARD_OUTFLOWS as $category => $rules) {
            if ($code !== '' && in_array($code, array_map($this->normalizeCode(...), $rules['abi']), true)) {
                return $category;
            }

            foreach ($rules['keywords'] as $pattern) {
                if ($text !== '' && preg_match($pattern, $text) === 1) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function normalizeCode(?string $code): string
    {
        return ltrim(strtoupper(trim((string) $code)), '0');
    }

    private function settle(BankStatementLine $line, Invoice|PassiveInvoice $document, BankStatement $statement, ReconciliationSettings $settings, bool $allowPartial, ?string $type = null): bool
    {
        $due = $this->dueAmount($document);

        if ($due <= 0 || $document->currency !== $statement->currency || ($line->isCredit() && (float) $line->fee_amount > $settings->maxFee)) {
            return false;
        }

        $delta = $this->delta($line, $due);

        $mode = match (true) {
            abs($delta) <= $settings->tolerance => self::MODE_COMPLETE,
            $delta > $settings->tolerance && $allowPartial && $settings->allowPartial => self::MODE_PARTIAL,
            default => null,
        };

        if ($mode === null) {
            return false;
        }

        $reconciliation = match (true) {
            $mode === self::MODE_PARTIAL => BankStatementLine::PARTIAL,
            $type !== null => $type,
            $delta != 0.0 => BankStatementLine::FUZZY_TOLERANCE,
            default => BankStatementLine::EXACT,
        };

        DB::transaction(fn () => $this->book(
            $line,
            $document instanceof PassiveInvoice ? PassiveInvoice::lockForUpdate()->findOrFail($document->id) : Invoice::lockForUpdate()->findOrFail($document->id),
            $mode,
            $reconciliation,
        ));

        return true;
    }

    /**
     * Book the payments of a line on a document: the net amount, the fee and, when the invoice is closed, the rounding
     * adjustment (a small excess over the balance is absorbed by reducing the net payment).
     */
    private function book(BankStatementLine $line, Invoice|PassiveInvoice $document, string $mode, string $reconciliation): void
    {
        $due = $this->dueAmount($document);
        $net = (float) $line->amount;
        $fee = (float) $line->fee_amount;
        $delta = $this->delta($line, $due);
        $common = ['paid_at' => $line->booking_date->toDateString(), 'reference' => $line->reference, 'bank_statement_line_id' => $line->id];

        $netPayment = $delta < 0 ? round($net + $delta, 2) : $net;

        if ($netPayment > 0) {
            $this->pay($document, $common + ['amount' => $netPayment, 'type' => 'payment', 'notes' => __('banking.payment_notes.payment')]);
        }

        if ($fee > 0) {
            $this->pay($document, $common + ['amount' => $fee, 'type' => 'bank_fee', 'notes' => __('banking.payment_notes.bank_fee')]);
        }

        if ($mode !== self::MODE_PARTIAL && $delta > 0) {
            $this->pay($document, $common + ['amount' => $delta, 'type' => 'adjustment', 'notes' => __('banking.payment_notes.adjustment')]);
        }

        $line->update([
            'reconciled' => true,
            'matched_document_id' => $document->id,
            'reconciliation_type' => $reconciliation,
            'reconciliation_category' => BankStatementLine::CATEGORY_INVOICE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pay(Invoice|PassiveInvoice $document, array $data): void
    {
        $document instanceof PassiveInvoice
            ? $this->supplierPayments->execute($document, $data)
            : $this->payments->execute($document, $data);
    }

    /**
     * Remove the payments a line booked and restore the balance of the document it was matched with.
     */
    private function revertPayments(BankStatementLine $line): void
    {
        if ($line->isCredit()) {
            Payment::query()->where('bank_statement_line_id', $line->id)->delete();
            $document = Invoice::find($line->matched_document_id);

            if ($document !== null) {
                $this->payments->recalculate($document);
            }

            return;
        }

        SupplierPayment::query()->where('bank_statement_line_id', $line->id)->delete();
        $document = PassiveInvoice::find($line->matched_document_id);

        if ($document !== null) {
            $this->supplierPayments->recalculate($document);
        }
    }

    /**
     * Balance still due on a document.
     */
    private function dueAmount(Invoice|PassiveInvoice $document): float
    {
        return $document instanceof PassiveInvoice
            ? round((float) $document->total_amount - (float) $document->total_paid, 2)
            : round((float) $document->total_gross - (float) $document->total_paid, 2);
    }

    private function isPayable(Invoice|PassiveInvoice $document): bool
    {
        return $document instanceof PassiveInvoice
            ? $document->document_type !== 'TD04'
            : ! in_array($document->invoice_type, ['proforma', 'credit_note'], true);
    }

    /**
     * What is still due after the line: the net amount plus the fee for an incoming line, the net amount alone for an
     * outgoing one.
     */
    private function delta(BankStatementLine $line, float $due): float
    {
        return round($due - ((float) $line->amount + ($line->isCredit() ? (float) $line->fee_amount : 0.0)), 2);
    }

    /**
     * Open customer invoices (`$customers`) or open received supplier invoices of the statement tenant.
     *
     * @return Builder<Invoice>|Builder<PassiveInvoice>
     */
    private function openDocuments(BankStatement $statement, bool $customers): Builder
    {
        if ($customers) {
            return Invoice::query()
                ->where('tenant_id', $statement->tenant_id)
                ->where('payment_status', '!=', 'paid')
                ->whereNotIn('invoice_type', ['proforma', 'credit_note']);
        }

        return PassiveInvoice::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('payment_status', '!=', 'paid')
            ->where('document_type', '!=', 'TD04');
    }

    private function findByQrReference(BankStatementLine $line, BankStatement $statement): ?Invoice
    {
        if ($line->qr_reference === null || $line->qr_reference === '') {
            return null;
        }

        return $this->openDocuments($statement, true)
            ->whereHas('qrBillData', fn (Builder $query) => $query->whereRaw("replace(upper(scor_reference), ' ', '') = ?", [strtoupper($line->qr_reference)]))
            ->first();
    }

    private function findByDocumentNumber(BankStatementLine $line, BankStatement $statement, bool $customers): Invoice|PassiveInvoice|null
    {
        $text = strtoupper(trim(($line->description ?? '').' '.($line->reference ?? '')));

        if ($text === '') {
            return null;
        }

        $key = $customers ? 'customers' : 'suppliers';
        $this->openNumbers[$key] ??= $this->openDocuments($statement, $customers)->pluck('invoice_number', 'id')->all();
        $found = null;

        foreach ($this->openNumbers[$key] as $id => $number) {
            $pattern = '/(?<![A-Z0-9])'.preg_quote(strtoupper((string) $number), '/').'(?![A-Z0-9])/';

            if ($number !== '' && preg_match($pattern, $text) === 1 && ($found === null || strlen((string) $number) > strlen((string) $this->openNumbers[$key][$found]))) {
                $found = $id;
            }
        }

        return $found === null ? null : $this->openDocuments($statement, $customers)->find($found);
    }

    /**
     * A customer (or supplier) recognised by IBAN or by VAT number / fiscal code in the description, with exactly one
     * open document whose balance matches the gross amount within the tolerance.
     */
    private function findByCounterpart(BankStatementLine $line, BankStatement $statement, ReconciliationSettings $settings, bool $customers): Invoice|PassiveInvoice|null
    {
        $contactIds = collect();

        if ($line->counterpart_iban !== null && $line->counterpart_iban !== '') {
            $contactIds = $contactIds->merge(
                Contact::query()->where('tenant_id', $statement->tenant_id)->whereNotNull('iban')
                    ->whereRaw("replace(upper(iban), ' ', '') = ?", [strtoupper(str_replace(' ', '', $line->counterpart_iban))])
                    ->pluck('id')
            );
        }

        $text = strtoupper((string) $line->description);

        if ($text !== '') {
            $contactIds = $contactIds->merge(
                Contact::query()->where('tenant_id', $statement->tenant_id)
                    ->where(fn (Builder $query) => $query->whereRaw('length(vat_number) >= 8')->orWhereRaw('length(fiscal_code) >= 11'))
                    ->get(['id', 'vat_number', 'fiscal_code'])
                    ->filter(fn (Contact $contact): bool => $this->mentions($text, $contact->vat_number) || $this->mentions($text, $contact->fiscal_code))
                    ->pluck('id')
            );
        }

        if ($contactIds->isEmpty()) {
            return null;
        }

        $owner = $customers ? 'contact_id' : 'supplier_id';
        $candidates = $this->openDocuments($statement, $customers)->whereIn($owner, $contactIds->unique()->all())->get()
            ->filter(fn (Invoice|PassiveInvoice $document): bool => $document->currency === $statement->currency && abs($this->delta($line, $this->dueAmount($document))) <= $settings->tolerance);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function mentions(string $text, ?string $identifier): bool
    {
        $identifier = strtoupper(trim((string) $identifier));

        return strlen($identifier) >= 8 && preg_match('/(?<![A-Z0-9])'.preg_quote($identifier, '/').'(?![A-Z0-9])/', $text) === 1;
    }
}
