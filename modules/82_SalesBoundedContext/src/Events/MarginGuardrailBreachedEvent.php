<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class MarginGuardrailBreachedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $guardrailId,
        public float $actualMarginPct,
        public float $minMarginPct,
    ) {}
}
