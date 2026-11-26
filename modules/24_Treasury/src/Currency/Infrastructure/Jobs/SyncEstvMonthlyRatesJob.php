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
 * The monthly synchronisation of the ESTV average rates used for Swiss VAT (Info IVA 16).
 */
final class SyncEstvMonthlyRatesJob implements ShouldQueue
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
        $handler->handle(new SyncExchangeRatesCommand([RateSource::Estv], $this->trigger, $this->tenantId, $this->requestedBy, $this->runIds));
    }
}
