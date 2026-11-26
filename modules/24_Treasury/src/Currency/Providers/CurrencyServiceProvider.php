<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Providers;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Treasury\Currency\Domain\Contracts\RateTableLoader;
use Modules\Treasury\Currency\Domain\Events\ExchangeRatesSynchronized;
use Modules\Treasury\Currency\Domain\Events\ExchangeRateSyncFailed;
use Modules\Treasury\Currency\Domain\Events\PaymentAllocated;
use Modules\Treasury\Currency\Domain\Services\CrossRateCalculator;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Http\Policies\ExchangeRatePolicy;
use Modules\Treasury\Currency\Http\Policies\FxRevaluationPolicy;
use Modules\Treasury\Currency\Infrastructure\Listeners\EvaluateRateMovementAlerts;
use Modules\Treasury\Currency\Infrastructure\Listeners\NotifyFinanceOfSyncFailure;
use Modules\Treasury\Currency\Infrastructure\Listeners\PostRealizedFxOnPaymentAllocated;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\EloquentRateTableLoader;
use Modules\Treasury\Currency\Infrastructure\Providers\EcbRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\EstvMonthlyRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\RateProviderRegistry;
use Modules\Treasury\Currency\Infrastructure\Providers\SnbRateProvider;

/**
 * Wires the multi-currency engine (Mod 003): the rate selection over the exchange-rate table, the official providers, the
 * policies, the routes and the consumers of the payment and synchronisation events.
 */
final class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RateTableLoader::class, EloquentRateTableLoader::class);
        $this->app->singleton(RateSelector::class, fn ($app): RateSelector => new RateSelector(
            $app->make(RateTableLoader::class),
            new CrossRateCalculator,
            (int) config('currency_fx.window_days', RateSelector::WINDOW_DAYS),
        ));
        $this->app->singleton(RateProviderRegistry::class, static fn (): RateProviderRegistry => new RateProviderRegistry([new EcbRateProvider, new SnbRateProvider, new EstvMonthlyRateProvider]));
    }

    public function boot(): void
    {
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);
        Gate::policy(CurrencyFxRevaluation::class, FxRevaluationPolicy::class);

        Event::listen(PaymentAllocated::class, [PostRealizedFxOnPaymentAllocated::class, 'handle']);
        Event::listen(ExchangeRateSyncFailed::class, [NotifyFinanceOfSyncFailure::class, 'handle']);
        Event::listen(ExchangeRatesSynchronized::class, [EvaluateRateMovementAlerts::class, 'handle']);

        $this->loadRoutesFrom(__DIR__.'/../../../routes/currency.php');
    }
}
