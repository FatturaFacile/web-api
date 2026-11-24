<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Observers;

use App\Models\Contact;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

final class ContactAuditObserver
{
    public function __construct(
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    public function created(Contact $contact): void
    {
        $this->auditLedger->record(
            action: 'CREATED',
            model: $contact,
            payloadBefore: null,
            payloadAfter: $contact->getAttributes(),
            moduleCode: 'CRM'
        );
    }

    public function updated(Contact $contact): void
    {
        $this->auditLedger->record(
            action: 'UPDATED',
            model: $contact,
            payloadBefore: $contact->getOriginal(),
            payloadAfter: $contact->getChanges(),
            moduleCode: 'CRM'
        );
    }

    public function deleted(Contact $contact): void
    {
        $this->auditLedger->record(
            action: 'DELETED',
            model: $contact,
            payloadBefore: $contact->getOriginal(),
            payloadAfter: null,
            moduleCode: 'CRM'
        );
    }
}
