<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Treasury\Currency\Application\Commands\SyncExchangeRatesCommand;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\SyncRunStatus;
use Modules\Treasury\Currency\Domain\Enums\SyncTrigger;
use Modules\Treasury\Currency\Domain\Events\ExchangeRatesSynchronized;
use Modules\Treasury\Currency\Domain\Events\ExchangeRateSyncFailed;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Domain\Services\CrossRateCalculator;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySetting;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateRepository;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateSyncRun;
use Modules\Treasury\Currency\Infrastructure\Providers\RateProviderRegistry;
use Throwable;

/**
 * Fetches the official rates of each provider, validates them and stores them (idempotently) as shared official rates, then
 * derives the cross rates between the tracked currencies (flagged derived). A provider that fails marks its run failed with the
 * error, stores no rate at all (the storage is one transaction) and announces the failure; no rate is ever invented.
 */
final readonly class SyncExchangeRatesHandler
{
    public function __construct(
        private RateProviderRegistry $providers,
        private ExchangeRateRepository $rates,
        private CrossRateCalculator $cross,
        private TenantContext $tenants,
    ) {}

    /**
     * @return list<ExchangeRateSyncRun>
     */
    public function handle(SyncExchangeRatesCommand $command): array
    {
        // Official rates are platform data: the runs and the rates are written outside any company context.
        return $this->tenants->runWithoutTenant(function () use ($command): array {
            $runs = [];

            foreach ($command->sources as $source) {
                $runs[] = $this->runProvider($command, $source);
            }

            return $runs;
        });
    }

    private function runProvider(SyncExchangeRatesCommand $command, RateSource $source): ExchangeRateSyncRun
    {
        $run = $this->openRun($command, $source);
        $run->forceFill(['status' => SyncRunStatus::Running->value, 'started_at' => CarbonImmutable::now(), 'error_message' => null])->save();

        try {
            $quotes = $this->providers->get($source)->fetch();

            if ($quotes === []) {
                throw new RateProviderException(strtoupper($source->value).' feed delivered no rate.');
            }

            $stats = DB::transaction(function () use ($source, $quotes): array {
                TransactionLock::acquire('exchange_rate_sync:'.$source->value);

                return $this->store($quotes);
            });
        } catch (Throwable $exception) {
            $run->forceFill(['status' => SyncRunStatus::Failed->value, 'finished_at' => CarbonImmutable::now(), 'error_message' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            Log::warning('Exchange-rate sync failed.', ['provider' => $source->value, 'run' => $run->id, 'reason' => $exception->getMessage()]);
            Event::dispatch(new ExchangeRateSyncFailed((string) $run->id, $source->value, $exception->getMessage(), $run->tenant_id === null ? null : (string) $run->tenant_id));

            return $run->refresh();
        }

        $run->forceFill([
            'status' => SyncRunStatus::Succeeded->value,
            'finished_at' => CarbonImmutable::now(),
            'rates_count' => $stats['rates'],
            'derived_count' => $stats['derived'],
            'latest_rate_date' => $stats['latest'],
        ])->save();

        Event::dispatch(new ExchangeRatesSynchronized((string) $run->id, $source->value, $stats['rates'], $stats['derived'], $stats['latest'], $stats['currencies']));

        return $run->refresh();
    }

    private function openRun(SyncExchangeRatesCommand $command, RateSource $source): ExchangeRateSyncRun
    {
        $id = $command->runIds[$source->value] ?? null;
        $run = $id === null ? null : ExchangeRateSyncRun::query()->withoutGlobalScopes()->whereKey($id)->first();

        if ($run !== null) {
            return $run;
        }

        $attributes = ['provider' => $source->value, 'status' => SyncRunStatus::Queued->value, 'trigger' => $command->trigger->value, 'requested_by' => $command->requestedBy];

        if ($command->tenantId === null && $command->trigger === SyncTrigger::Scheduled) {
            return $this->tenants->runAsGlobal(static fn (): ExchangeRateSyncRun => ExchangeRateSyncRun::query()->create($attributes + ['tenant_id' => null]));
        }

        $run = new ExchangeRateSyncRun;
        $run->forceFill($attributes + ['tenant_id' => $command->tenantId]);
        $run->save();

        return $run;
    }

    /**
     * @param  list<RateQuote>  $quotes
     * @return array{rates: int, derived: int, latest: string, currencies: list<string>}
     */
    private function store(array $quotes): array
    {
        $latest = '0000-00-00';
        $currencies = [];
        $groups = [];

        foreach ($quotes as $quote) {
            $this->rates->record(null, $quote->pair, $quote->rate, $quote->type, $quote->source, $quote->validOn);
            $latest = max($latest, $quote->validOn);
            $currencies[$quote->pair->base->value] = true;
            $currencies[$quote->pair->quote->value] = true;
            $groups[$quote->type->value.'|'.$quote->validOn][] = $quote;
        }

        $tracked = $this->trackedCurrencies();
        $derived = 0;

        foreach ($groups as $group) {
            if ($group[0]->type === RateType::Mid) {
                $derived += $this->storeDerived($group, $tracked);
            }
        }

        return ['rates' => count($quotes), 'derived' => $derived, 'latest' => $latest, 'currencies' => array_keys($currencies)];
    }

    /**
     * Cross rates (and inverses) between the tracked currencies for the quotes of one day, through the currency every quote is
     * expressed against (EUR for the ECB, CHF for the SNB).
     *
     * @param  list<RateQuote>  $group
     * @param  list<string>  $tracked
     */
    private function storeDerived(array $group, array $tracked): int
    {
        $bases = array_values(array_unique(array_map(static fn (RateQuote $quote): string => $quote->pair->base->value, $group)));
        $counters = array_values(array_unique(array_map(static fn (RateQuote $quote): string => $quote->pair->quote->value, $group)));
        $pivot = count($bases) === 1 ? $bases[0] : (count($counters) === 1 ? $counters[0] : null);
        $units = [];
        $published = [];

        foreach ($group as $quote) {
            $published[$quote->pair->key()] = true;
            $base = $quote->pair->base->value;
            $counter = $quote->pair->quote->value;

            if ($pivot === $base) {
                $units[$counter] = $quote->rate->value;
            } elseif ($pivot === $counter) {
                $units[$base] = Dec::div('1', $quote->rate->value);
            }
        }

        if ($pivot === null || $units === []) {
            return 0;
        }

        $count = 0;
        $source = $group[0]->source;

        foreach ($this->cross->derivePairs($pivot, $units, $tracked) as $derived) {
            /** @var CurrencyPair $pair */
            $pair = $derived['pair'];

            if (isset($published[$pair->key()])) {
                continue;
            }

            $this->rates->record(null, $pair, $derived['rate'], RateType::Mid, $source, $group[0]->validOn, true);
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function trackedCurrencies(): array
    {
        $tracked = array_map('strtoupper', (array) config('currency_fx.tracked_currencies', []));

        foreach (CurrencySetting::query()->withoutGlobalScopes()->pluck('active_currencies') as $active) {
            foreach ((array) $active as $currency) {
                $tracked[] = strtoupper((string) $currency);
            }
        }

        return array_values(array_unique($tracked));
    }
}
