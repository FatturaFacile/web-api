<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Models\PassiveInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers payments made to a supplier and keeps the paid total and the payment status of the received invoice in
 * line with them (`unpaid`, `partially_paid`, `paid`). Only the amount imputed to the supply counts (payments and
 * adjustments): a `bank_fee` entry is a separate bank cost of the company and never reduces the debt.
 */
class RecordSupplierPayment
{
    /**
     * @param  array{amount: float|int|string, paid_at: string, type?: string, reference?: ?string, notes?: ?string, bank_statement_line_id?: ?string}  $data
     *
     * @throws ValidationException when the amount exceeds the balance of the invoice
     */
    public function execute(PassiveInvoice $invoice, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($invoice, $data): SupplierPayment {
            $invoice = PassiveInvoice::withoutGlobalScopes()
                ->where('id', $invoice->id)
                ->when($invoice->tenant_id, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->firstOrFail();
            $amount = round((float) $data['amount'], 2);

            if (($data['type'] ?? 'payment') !== 'bank_fee' && $amount > round((float) $invoice->total_amount - (float) $invoice->total_paid, 2)) {
                throw ValidationException::withMessages(['amount' => __('banking.errors.supplier_payment_exceeds_balance')]);
            }

            $payment = $invoice->payments()->create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'type' => $data['type'] ?? 'payment',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'bank_statement_line_id' => $data['bank_statement_line_id'] ?? null,
            ]);

            $this->recalculate($invoice);

            return $payment;
        });
    }

    /**
     * Re-derive the paid total and status of a received invoice after payments were added or removed.
     */
    public function recalculate(PassiveInvoice $invoice): PassiveInvoice
    {
        return DB::transaction(function () use ($invoice): PassiveInvoice {
            $invoice = PassiveInvoice::withoutGlobalScopes()
                ->where('id', $invoice->id)
                ->when($invoice->tenant_id, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->firstOrFail();
            $paid = round((float) $invoice->payments()->where('type', '!=', 'bank_fee')->sum('amount'), 2);

            $invoice->total_paid = $paid;
            $invoice->payment_status = match (true) {
                $paid <= 0 => 'unpaid',
                $paid >= (float) $invoice->total_amount => 'paid',
                default => 'partially_paid',
            };
            $invoice->is_reconciled = $invoice->payment_status === 'paid';
            $invoice->save();

            return $invoice;
        });
    }
}
