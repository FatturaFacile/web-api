<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Events;

/**
 * The invoice was recalled before the debtor approved it.
 */
final readonly class EbillCancelled
{
    public function __construct(
        public string $transmissionId,
        public string $invoiceId,
        public string $tenantId,
        public string $occurredAt,
    ) {}
}
