<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Unworked material came back from a vendor.
 */
final readonly class UnusedMaterialsReturned
{
    use Dispatchable;

    public function __construct(
        public string $subcontractingOrderId,
        public string $tenantId,
        public string $quantity,
        public ?string $ddtDocumentId = null,
    ) {}
}
