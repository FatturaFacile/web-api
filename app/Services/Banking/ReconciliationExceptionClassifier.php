<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\SwissQrBill\QrBillGeneratorService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Says why the automatic reconciliation left a bank movement open, so the review queue can be worked by cause:
 *
 * - `InvalidReference`: the structured reference of the payment fails its checksum (QR reference modulo 10, ISO 11649
 *   modulo 97), usually a mistyped or truncated reference;
 * - `DuplicatePayment`: the invoice the reference points to was already settled by another bank movement;
 * - `AlreadyPaid`: the invoice the reference points to is paid, but not by a bank movement of this system;
 * - `AmountMismatch`: an open document was recognised (reference or invoice number) but the amount, fee or currency
 *   does not fit it;
 * - `Unmatched`: nothing recognised.
 */
final class ReconciliationExceptionClassifier
{
    public const string UNMATCHED = 'Unmatched';

    public const string AMOUNT_MISMATCH = 'AmountMismatch';

    public const string DUPLICATE_PAYMENT = 'DuplicatePayment';

    public const string ALREADY_PAID = 'AlreadyPaid';

    public const string INVALID_REFERENCE = 'InvalidReference';

    /**
     * Every exception code, in the order the review queue lists them.
     *
     * @var list<string>
     */
    public const array CODES = [
        self::UNMATCHED,
        self::AMOUNT_MISMATCH,
        self::DUPLICATE_PAYMENT,
        self::ALREADY_PAID,
        self::INVALID_REFERENCE,
    ];

    public function __construct(private readonly QrBillGeneratorService $references) {}

    /**
     * @param  Invoice|PassiveInvoice|null  $suggested  the open document the line points to by invoice number or reference
     * @return array{code: string, candidate_id: string|null}
     */
    public function classify(BankStatementLine $line, Invoice|PassiveInvoice|null $suggested): array
    {
        $reference = strtoupper((string) preg_replace('/\s+/', '', (string) $line->qr_reference));

        if ($reference !== '' && ! $this->isValidReference($reference)) {
            return ['code' => self::INVALID_REFERENCE, 'candidate_id' => null];
        }

        if ($reference !== '' && $line->isCredit()) {
            $invoice = $this->invoiceForReference($reference, (string) $line->tenant_id);

            if ($invoice !== null) {
                if ($this->settledByAnotherLine($line, (string) $invoice->id)) {
                    return ['code' => self::DUPLICATE_PAYMENT, 'candidate_id' => null];
                }

                if ($invoice->payment_status === 'paid') {
                    return ['code' => self::ALREADY_PAID, 'candidate_id' => null];
                }

                $suggested ??= $invoice;
            }
        }

        if ($suggested !== null) {
            return ['code' => self::AMOUNT_MISMATCH, 'candidate_id' => (string) $suggested->id];
        }

        return ['code' => self::UNMATCHED, 'candidate_id' => null];
    }

    /**
     * A 27 digit QR reference with a valid modulo 10 check digit, or an ISO 11649 creditor reference.
     */
    public function isValidReference(string $reference): bool
    {
        return $this->references->validateScorReference($reference) || $this->references->validateIso11649($reference);
    }

    private function settledByAnotherLine(BankStatementLine $line, string $invoiceId): bool
    {
        return BankStatementLine::query()
            ->where('tenant_id', $line->tenant_id)
            ->where('matched_document_id', $invoiceId)
            ->where('reconciled', true)
            ->whereKeyNot($line->id)
            ->exists();
    }

    private function invoiceForReference(string $reference, string $tenantId): ?Invoice
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $query) => $query
                ->where('qr_reference', $reference)
                ->orWhereHas('qrBillData', fn (Builder $data) => $data->whereRaw("replace(upper(scor_reference), ' ', '') = ?", [$reference])))
            ->first();
    }
}
