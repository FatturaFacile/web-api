<?php

declare(strict_types=1);

namespace App\Events\Sales;

/**
 * A payment was booked on a customer invoice (bank import, camt.054 matching, manual entry). Dispatched by RecordPayment inside
 * its transaction, after the invoice totals and the ledger were updated; listeners of other modules must not throw.
 */
final readonly class CustomerPaymentRecorded
{
    public function __construct(
        public string $invoiceId,
        public string $paymentId,
        public string $tenantId,
    ) {}
}
