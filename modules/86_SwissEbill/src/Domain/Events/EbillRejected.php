<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The debtor rejected the invoice in the e-banking.
 */
final readonly class EbillRejected
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
