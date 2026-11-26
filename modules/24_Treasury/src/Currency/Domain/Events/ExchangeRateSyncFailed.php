<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

/**
 * A synchronisation failed: the run is marked failed, no rate was stored, and the finance team of the company (or of every
 * company for a scheduled run) is told.
 */
final readonly class ExchangeRateSyncFailed
{
    public function __construct(
        public string $runId,
        public string $provider,
        public string $error,
        public ?string $tenantId = null,
    ) {}
}
