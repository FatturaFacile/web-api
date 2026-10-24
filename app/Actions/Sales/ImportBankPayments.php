<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Invoice;
use App\Models\PaymentImport;
use App\Models\PaymentImportLine;
use App\Services\Banking\CamtParser;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBankPayments
{
    public function __construct(
        private readonly CamtParser $parser,
        private readonly RecordPayment $recordPayment,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Import a camt.053/camt.054 file: new entries are matched to invoices (QR/SCOR reference first, then the
     * invoice number in the remittance text) and booked as payments. Already imported entries are skipped.
     */
    public function execute(string $xml, string $fileName): PaymentImport
    {
        $entries = $this->parser->parse($xml);
        $tenantId = $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($entries, $fileName, $tenantId): PaymentImport {
            $import = PaymentImport::create([
                'tenant_id' => $tenantId,
                'user_id' => Auth::id(),
                'file_name' => $fileName,
                'total_entries' => count($entries),
            ]);

            $matched = 0;
            $fees = 0.0;
            $duplicates = 0;

            foreach ($entries as $entry) {
                if (PaymentImportLine::where('entry_hash', $entry['hash'])->exists()) {
                    $duplicates++;

                    continue;
                }

                $line = $import->lines()->create([
                    'tenant_id' => $tenantId,
                    'entry_hash' => $entry['hash'],
                    'booking_date' => $entry['booking_date'],
                    'amount' => $entry['amount'],
                    'currency' => $entry['currency'],
                    'reference' => $entry['reference'],
                    'remittance_info' => $entry['remittance_info'],
                    'debtor_name' => $entry['debtor_name'],
                    'fee_amount' => $entry['fee_amount'],
                    'reference_type' => $entry['reference_type'],
                ]);
                $fees += $entry['fee_amount'];

                $invoice = $this->findInvoice($entry);

                if ($invoice !== null && $this->book($line, $invoice)) {
                    $matched++;
                }
            }

            $import->update(['matched_entries' => $matched, 'duplicate_entries' => $duplicates, 'total_fees' => round($fees, 2)]);

            return $import->load('lines');
        });
    }

    /**
     * Manually book an unmatched line against a chosen invoice.
     */
    public function matchManually(PaymentImportLine $line, Invoice $invoice): PaymentImportLine
    {
        if ($line->status === 'matched') {
            throw ValidationException::withMessages(['line' => __('sales.import_line_matched')]);
        }

        return DB::transaction(function () use ($line, $invoice): PaymentImportLine {
            $payment = $this->recordPayment->execute($invoice, $this->paymentData($line));
            $line->update(['status' => 'matched', 'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'note' => null]);
            $line->paymentImport()->increment('matched_entries');

            return $line->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function findInvoice(array $entry): ?Invoice
    {
        $open = fn () => Invoice::query()->where('payment_status', '!=', 'paid')->whereNotIn('invoice_type', ['proforma', 'credit_note']);

        if ($entry['reference'] !== null) {
            $byReference = $open()->whereHas('qrBillData', fn ($q) => $q->whereRaw("replace(scor_reference, ' ', '') = ?", [$entry['reference']]))->first();

            if ($byReference !== null) {
                return $byReference;
            }
        }

        $text = trim(($entry['remittance_info'] ?? '').' '.($entry['reference'] ?? ''));

        if ($text === '') {
            return null;
        }

        return $open()->get()->first(fn (Invoice $invoice): bool => str_contains($text, $invoice->invoice_number));
    }

    private function book(PaymentImportLine $line, Invoice $invoice): bool
    {
        if ($invoice->currency !== $line->currency) {
            $line->update(['note' => "Currency mismatch with invoice {$invoice->invoice_number}."]);

            return false;
        }

        try {
            $payment = DB::transaction(fn () => $this->recordPayment->execute($invoice, $this->paymentData($line)));
        } catch (ValidationException $e) {
            $line->update(['note' => "Invoice {$invoice->invoice_number}: ".collect($e->errors())->flatten()->implode(' ')]);

            return false;
        }

        $line->update(['status' => 'matched', 'invoice_id' => $invoice->id, 'payment_id' => $payment->id]);

        return true;
    }

    /**
     * @return array{amount: float, paid_at: string, payment_method: null, reference: ?string, notes: ?string}
     */
    private function paymentData(PaymentImportLine $line): array
    {
        return [
            'amount' => (float) $line->amount,
            'paid_at' => ($line->booking_date ?? today())->toDateString(),
            'payment_method' => null,
            'reference' => $line->reference,
            'notes' => __('sales.bank_import_note', ['detail' => $line->remittance_info ?? $line->debtor_name ?? '']),
        ];
    }
}
