<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The invoice reached the e-banking portal of the debtor.
 */
final readonly class EbillDelivered
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
