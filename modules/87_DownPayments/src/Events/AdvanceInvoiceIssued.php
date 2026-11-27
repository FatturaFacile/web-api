<?php

declare(strict_types=1);

namespace Modules\DownPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An advance invoice (TD02) was issued and recorded in a chain.
 */
final readonly class AdvanceInvoiceIssued
{
    use Dispatchable;

    public function __construct(
        public string $chainId,
        public string $linkId,
        public string $invoiceId,
        public string $tenantId,
    ) {}
}
