<?php

declare(strict_types=1);

namespace Modules\DownPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A held deposit was imputed to the price, forfeited or refunded.
 */
final readonly class DepositResolved
{
    use Dispatchable;

    public function __construct(
        public string $chainId,
        public string $depositId,
        public string $resolution,
        public string $tenantId,
    ) {}
}
