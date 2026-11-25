<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class GdoRebateAccruedOrSettledEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $agreementId,
        public string $settlementStatus,
        public float $totalProvisionedAccrual,
        public ?string $creditNoteId = null,
    ) {}
}
