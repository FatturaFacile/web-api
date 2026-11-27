<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The transmission failed for a technical reason (gateway, schema or network).
 */
final readonly class EbillFailed
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
