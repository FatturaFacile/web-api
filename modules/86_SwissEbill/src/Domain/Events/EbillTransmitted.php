<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The gateway accepted the invoice (SIX transaction id and submission time are stored).
 */
final readonly class EbillTransmitted
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
