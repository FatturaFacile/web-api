<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The verification of a tenant chain failed: an entry was altered (`brokenAtSequence`) or a range was deleted
 * (`missingFromSequence`..`missingToSequence`). Handled by the incident notification to owners and the CISO (Mod 008).
 */
final readonly class AuditChainBroken
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $runId,
        public string $outcome,
        public ?int $brokenAtSequence = null,
        public ?int $missingFromSequence = null,
        public ?int $missingToSequence = null,
    ) {}
}
