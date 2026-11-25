<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The royalty and marketing fund invoices of a month were issued to the stores.
 */
final class RoyaltyRunInvoiced
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly string $period,
    ) {}
}
