<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\SyncTrigger;

/**
 * Runs the rate providers: fetches, validates and stores the official rates and derives the cross rates. `runIds` are the runs
 * already queued for the providers (a manual trigger); without them a run is opened per provider.
 */
final readonly class SyncExchangeRatesCommand
{
    /**
     * @param  list<RateSource>  $sources
     * @param  array<string, string>  $runIds  provider value => queued run id
     */
    public function __construct(
        public array $sources,
        public SyncTrigger $trigger = SyncTrigger::Scheduled,
        public ?string $tenantId = null,
        public ?string $requestedBy = null,
        public array $runIds = [],
    ) {}
}
