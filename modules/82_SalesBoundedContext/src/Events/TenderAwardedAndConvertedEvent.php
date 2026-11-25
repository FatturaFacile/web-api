<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class TenderAwardedAndConvertedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $tenderId,
        public ?string $convertedOrderId,
        public ?string $cigCode,
        public ?string $cupCode,
    ) {}
}
