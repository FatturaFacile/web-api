<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The debtor approved the payment in the e-banking.
 */
final readonly class EbillApproved
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
