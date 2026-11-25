<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CorporateWelfareSplitBilledEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $conventionId,
        public float $employerWelfareQuota,
        public float $employeeCopayQuota,
        public bool $withinStatutoryExemption,
    ) {}
}
