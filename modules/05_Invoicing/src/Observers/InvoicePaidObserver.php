<?php

declare(strict_types=1);

namespace Modules\Invoicing\Observers;

use App\Models\Invoice;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Throwable;

/**
 * Raises InvoicePaidEvent when payment_status of a sales invoice changes TO "paid", whichever code path saved it: a
 * payment recorded (RecordPayment), a bank reconciliation, a credit note applied (InvoicingService), the fiscal
 * service. The model reports the change only on the save that makes it, so the event is raised once per transition
 * (an invoice that falls back to partially_paid and is settled again is a new transition). Proforma invoices and credit
 * notes never raise it.
 *
 * It is registered on both models that write the invoices table. It never breaks the save that triggered it: a failure
 * is logged and rolled back to a savepoint.
 */
final class InvoicePaidObserver
{
    public function __construct(private readonly EventDispatcher $dispatcher) {}

    public function updated(Invoice|SalesInvoice $invoice): void
    {
        if (! $invoice->wasChanged('payment_status') || $invoice->payment_status !== 'paid') {
            return;
        }

        if (in_array($invoice->invoice_type, ['proforma', 'credit_note'], true)) {
            return;
        }

        $tenantId = (string) $invoice->tenant_id;

        if (! Str::isUuid($tenantId)) {
            return;
        }

        try {
            DB::transaction(fn () => $this->dispatcher->publishAndDispatch(new InvoicePaidEvent(
                invoiceId: (string) $invoice->id,
                invoiceNumber: (string) $invoice->invoice_number,
                customerId: (string) $invoice->contact_id ?: null,
                currency: (string) $invoice->currency,
                totalGross: (float) $invoice->total_gross,
                totalPaid: (float) $invoice->total_paid,
                paidAt: now()->toIso8601String(),
                tenantId: $tenantId,
                companyId: $tenantId,
            )));
        } catch (Throwable $exception) {
            Log::error('The invoice paid event could not be raised.', ['invoice_id' => $invoice->id, 'exception' => $exception->getMessage()]);
        }
    }
}
