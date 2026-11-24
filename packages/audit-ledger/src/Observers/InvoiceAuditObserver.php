<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Observers;

use App\Models\Invoice;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

final class InvoiceAuditObserver
{
    public function __construct(
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    public function created(Invoice $invoice): void
    {
        $this->auditLedger->record(
            action: 'CREATED',
            model: $invoice,
            payloadBefore: null,
            payloadAfter: $invoice->getAttributes(),
            moduleCode: 'INVOICING'
        );
    }

    public function updated(Invoice $invoice): void
    {
        $action = ($invoice->wasChanged('payment_status') && $invoice->payment_status === 'cancelled')
            ? 'CANCELLED'
            : 'UPDATED';

        $this->auditLedger->record(
            action: $action,
            model: $invoice,
            payloadBefore: $invoice->getOriginal(),
            payloadAfter: $invoice->getChanges(),
            moduleCode: 'INVOICING'
        );
    }

    public function deleted(Invoice $invoice): void
    {
        $action = $invoice->isForceDeleting() ? 'DELETED' : 'CANCELLED';

        $this->auditLedger->record(
            action: $action,
            model: $invoice,
            payloadBefore: $invoice->getOriginal(),
            payloadAfter: null,
            moduleCode: 'INVOICING'
        );
    }
}
