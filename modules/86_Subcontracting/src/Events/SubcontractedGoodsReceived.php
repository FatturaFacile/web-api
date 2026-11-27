<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Processed goods came back from a vendor and the components were backflushed.
 */
final readonly class SubcontractedGoodsReceived
{
    use Dispatchable;

    public function __construct(
        public string $subcontractingOrderId,
        public string $tenantId,
        public string $quantity,
        public ?string $ddtDocumentId = null,
    ) {}
}
