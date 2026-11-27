<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Customer-owned material was loaded into the segregated customer-goods warehouse at zero value.
 */
final readonly class CustomerMaterialsReceived
{
    use Dispatchable;

    public function __construct(
        public string $subcontractingOrderId,
        public string $tenantId,
        public string $quantity,
        public ?string $ddtDocumentId = null,
    ) {}
}
