<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Observers;

use App\Models\Payment;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

final class PaymentAuditObserver
{
    public function __construct(
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    public function created(Payment $payment): void
    {
        $this->auditLedger->record(
            action: 'CREATED',
            model: $payment,
            payloadBefore: null,
            payloadAfter: $payment->getAttributes(),
            moduleCode: 'TREASURY'
        );
    }

    public function updated(Payment $payment): void
    {
        $this->auditLedger->record(
            action: 'UPDATED',
            model: $payment,
            payloadBefore: $payment->getOriginal(),
            payloadAfter: $payment->getChanges(),
            moduleCode: 'TREASURY'
        );
    }

    public function deleted(Payment $payment): void
    {
        $this->auditLedger->record(
            action: 'DELETED',
            model: $payment,
            payloadBefore: $payment->getOriginal(),
            payloadAfter: null,
            moduleCode: 'TREASURY'
        );
    }
}
