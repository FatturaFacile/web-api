<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Events;

/**
 * A received supplier invoice was registered and its journal entry posted: modules that react to the posted document (the foreign VAT
 * refund interception) listen to it. It carries ids only; the listener reads the invoice.
 */
final readonly class PassiveInvoicePostedEvent
{
    public function __construct(
        public string $invoiceId,
        public string $tenantId,
    ) {}
}
