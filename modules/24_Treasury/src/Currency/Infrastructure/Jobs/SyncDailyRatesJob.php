<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Treasury\Currency\Application\Commands\SyncExchangeRatesCommand;
use Modules\Treasury\Currency\Application\Handlers\SyncExchangeRatesHandler;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\SyncTrigger;

/**
 * The daily synchronisation of the ECB (EUR base feed, published around 16:00 CET) and SNB rates, scheduled or triggered by
 * hand. Concurrent executions are serialised by the transaction lock of the handler, and a provider still running is never
 * started a second time (see RequestExchangeRateSyncHandler).
 */
final class SyncDailyRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<string, string>  $runIds  provider value => queued run id (manual trigger)
     */
    public function __construct(
        public readonly SyncTrigger $trigger = SyncTrigger::Scheduled,
        public readonly ?string $tenantId = null,
        public readonly ?string $requestedBy = null,
        public readonly array $runIds = [],
    ) {}

    public function handle(SyncExchangeRatesHandler $handler): void
    {
        $sources = $this->runIds === []
            ? [RateSource::Ecb, RateSource::Snb]
            : array_values(array_filter([RateSource::Ecb, RateSource::Snb], fn (RateSource $source): bool => isset($this->runIds[$source->value])));

        $handler->handle(new SyncExchangeRatesCommand($sources, $this->trigger, $this->tenantId, $this->requestedBy, $this->runIds));
    }
}
