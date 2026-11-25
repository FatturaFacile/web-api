<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class QuoteConvertedToCommercialDocumentEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $quoteId,
        public string $targetKind,
        public string $targetDocumentId,
    ) {}
}
