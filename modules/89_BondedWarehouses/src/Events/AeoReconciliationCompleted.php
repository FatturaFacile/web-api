<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Events;

/**
 * An AEO reconciliation ran; $critical is the number of findings to report to the authority.
 */
final readonly class AeoReconciliationCompleted
{
    public function __construct(
        public string $authorizationId,
        public int $audits,
        public int $critical,
    ) {}
}
