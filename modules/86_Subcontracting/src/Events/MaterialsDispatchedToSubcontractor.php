<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Our materials left for a vendor with the DDT Conto Lavoro.
 */
final readonly class MaterialsDispatchedToSubcontractor
{
    use Dispatchable;

    public function __construct(
        public string $subcontractingOrderId,
        public string $tenantId,
        public string $quantity,
        public ?string $ddtDocumentId = null,
    ) {}
}
