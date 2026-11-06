<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\PaymentLedgerService;

class RecordPayment
{
    public function __construct(
        private readonly DocumentEventRecorder $events,
        private readonly PaymentLedgerService $ledger,
    ) {}

    /**
     * Register a (partial) payment and re-derive the invoice's paid total and payment status.
     *
     * @param  array{amount: float|int|string, paid_at: string, payment_method?: ?string, reference?: ?string, notes?: ?string, type?: string, bank_statement_line_id?: ?string}  $data
     */
    public function execute(Invoice $invoice, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $data): Payment {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->invoice_type === 'proforma') {
                throw ValidationException::withMessages(['invoice' => __('sales.payment_on_proforma')]);
            }

            $amount = round((float) $data['amount'], 2);
            $outstanding = (float) $invoice->outstandingAmount();

            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['amount' => __('sales.payment_exceeds_balance')]);
            }

            $payment = $invoice->payments()->create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'type' => $data['type'] ?? 'payment',
                'bank_statement_line_id' => $data['bank_statement_line_id'] ?? null,
            ]);

            $totalPaid = $this->settledAmount($invoice);
            $invoice->total_paid = $totalPaid;
            $invoice->payment_status = match (true) {
                $totalPaid <= 0 => 'unpaid',
                $totalPaid >= (float) $invoice->amountDue() => 'paid',
                default => 'partially_paid',
            };
            $invoice->save();

            $this->ledger->postCustomerPayment($payment, $data['ledger_options'] ?? []);
            $this->events->record($invoice, 'payment_recorded', ['amount' => $amount, 'payment_id' => $payment->id]);

            return $payment;
        });
    }

    /**
     * Re-derive the paid total and payment status of an invoice after payments have been added or removed.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            $totalPaid = $this->settledAmount($invoice);
            $invoice->total_paid = $totalPaid;
            $invoice->payment_status = match (true) {
                $totalPaid <= 0 => 'unpaid',
                $totalPaid >= (float) $invoice->amountDue() => 'paid',
                default => 'partially_paid',
            };
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * What closed the invoice so far: its payments and the credit notes applied to it.
     */
    private function settledAmount(Invoice $invoice): float
    {
        $credited = (float) DB::table('invoice_credit_applications')->where('original_invoice_id', $invoice->id)->sum('applied_amount');

        return round((float) $invoice->payments()->sum('amount') + $credited, 2);
    }
}
