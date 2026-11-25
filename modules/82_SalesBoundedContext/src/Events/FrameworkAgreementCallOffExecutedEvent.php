<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class FrameworkAgreementCallOffExecutedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $frameworkOrderId,
        public string $callOffOrderId,
        public string $callOffAmount,
        public string $remainingCeiling,
    ) {}
}
