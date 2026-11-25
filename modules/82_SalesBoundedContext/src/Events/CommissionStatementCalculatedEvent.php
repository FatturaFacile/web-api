<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CommissionStatementCalculatedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $statementId,
        public string $agentName,
        public string $accrualBasis,
        public string $netPayableAmount,
    ) {}
}
