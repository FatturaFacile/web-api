<?php

declare(strict_types=1);

namespace Modules\DownPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The final settlement invoice of a chain was issued with the deduction of its advances.
 */
final readonly class FinalSettlementGenerated
{
    use Dispatchable;

    public function __construct(
        public string $chainId,
        public string $invoiceId,
        public string $tenantId,
    ) {}
}
