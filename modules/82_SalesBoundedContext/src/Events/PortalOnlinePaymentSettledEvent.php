<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class PortalOnlinePaymentSettledEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $transactionId,
        public string $provider,
        public string $amount,
        public string $currency,
    ) {}
}
