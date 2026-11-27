<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Listeners;

use App\Events\Sales\CustomerPaymentRecorded;
use Illuminate\Support\Facades\Log;
use Modules\SwissEbill\Application\Services\EbillSettlementReconciler;
use Throwable;

/**
 * Reads every payment booked on a customer invoice (camt.054 import included) and settles the eBill of that invoice. It runs inside
 * the payment transaction and never throws: a problem of the eBill side is logged, the payment is never lost for it.
 */
final class ReconcileEbillOnPaymentRecorded
{
    public function __construct(private readonly EbillSettlementReconciler $reconciler) {}

    public function handle(CustomerPaymentRecorded $event): void
    {
        try {
            $this->reconciler->reconcile($event->invoiceId, $event->paymentId);
        } catch (Throwable $e) {
            Log::warning('Swiss eBill settlement reconciliation failed', ['invoice_id' => $event->invoiceId, 'payment_id' => $event->paymentId, 'error' => $e->getMessage()]);
        }
    }
}
