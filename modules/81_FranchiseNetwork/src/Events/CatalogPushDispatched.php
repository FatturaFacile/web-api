<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A central push was delivered (some stores may still have failed and be retried).
 */
final class CatalogPushDispatched
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $pushId,
    ) {}
}
