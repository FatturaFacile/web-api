<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Processed customer goods were returned with the DDT Reso lavorato.
 */
final readonly class ActiveGoodsDelivered
{
    use Dispatchable;

    public function __construct(
        public string $subcontractingOrderId,
        public string $tenantId,
        public string $quantity,
        public ?string $ddtDocumentId = null,
    ) {}
}
