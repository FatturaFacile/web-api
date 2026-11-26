<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\SyncRunStatus;
use Modules\Treasury\Currency\Domain\Enums\SyncTrigger;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncDailyRatesJob;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncEstvMonthlyRatesJob;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateSyncRun;

/**
 * The "Aggiorna ora" trigger: opens a queued run per provider for the company and queues the job. A provider already queued
 * or running (for the platform or for the company) refuses the trigger with 409 instead of starting a second feed.
 */
final readonly class RequestExchangeRateSyncHandler
{
    /** A run queued or running for longer than this is considered lost and no longer blocks a new one. */
    private const int STALE_AFTER_MINUTES = 30;

    /**
     * @param  list<RateSource>  $sources
     * @return list<ExchangeRateSyncRun>
     */
    public function handle(string $tenantId, ?string $userId, array $sources): array
    {
        $sources = $sources === [] ? [RateSource::Ecb, RateSource::Snb] : array_values(array_unique($sources, SORT_REGULAR));

        $runs = DB::transaction(function () use ($tenantId, $userId, $sources): array {
            foreach ($sources as $source) {
                if ($this->hasActiveRun($tenantId, $source)) {
                    throw new CurrencyDomainException(__('currency_fx.errors.sync_already_running', ['provider' => strtoupper($source->value)]), 'sync_already_running', 409);
                }
            }

            return array_map(fn (RateSource $source): ExchangeRateSyncRun => ExchangeRateSyncRun::query()->create([
                'tenant_id' => $tenantId,
                'provider' => $source->value,
                'status' => SyncRunStatus::Queued->value,
                'trigger' => SyncTrigger::Manual->value,
                'requested_by' => $userId,
            ]), $sources);
        });

        $daily = [];
        $estv = [];

        foreach ($runs as $run) {
            $run->provider === RateSource::Estv->value ? $estv[$run->provider] = (string) $run->id : $daily[$run->provider] = (string) $run->id;
        }

        if ($daily !== []) {
            SyncDailyRatesJob::dispatch(SyncTrigger::Manual, $tenantId, $userId, $daily);
        }

        if ($estv !== []) {
            SyncEstvMonthlyRatesJob::dispatch(SyncTrigger::Manual, $tenantId, $userId, $estv);
        }

        return array_map(static fn (ExchangeRateSyncRun $run): ExchangeRateSyncRun => $run->refresh(), $runs);
    }

    private function hasActiveRun(string $tenantId, RateSource $source): bool
    {
        return ExchangeRateSyncRun::query()
            ->withoutGlobalScopes()
            ->where('provider', $source->value)
            ->whereIn('status', [SyncRunStatus::Queued->value, SyncRunStatus::Running->value])
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->exists();
    }
}
