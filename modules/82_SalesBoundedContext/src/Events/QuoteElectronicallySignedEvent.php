<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class QuoteElectronicallySignedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $quoteId,
        public string $signatureStandard,
        public string $documentHash,
    ) {}
}
