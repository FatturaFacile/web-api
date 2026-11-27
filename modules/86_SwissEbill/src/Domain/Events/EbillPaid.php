<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * A camt.054 credit settled the invoice.
 */
final readonly class EbillPaid
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
