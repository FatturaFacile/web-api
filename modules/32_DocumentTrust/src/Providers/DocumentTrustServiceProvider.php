<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Drivers\LocalClockTimestampProvider;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Policies\PreservationPackagePolicy;

/**
 * Registers the routes, the policy and the time-stamp provider of the Document Trust module.
 */
final class DocumentTrustServiceProvider extends ServiceProvider
{
    /**
     * Bind the time-stamp provider: `services.preservation.timestamp_provider` is `local` (the server clock, which is not
     * a qualified time stamp) or the class name of a provider that implements TrustedTimestampProvider.
     */
    public function register(): void
    {
        $this->app->bind(TrustedTimestampProvider::class, static function ($app): TrustedTimestampProvider {
            $configured = (string) config('services.preservation.timestamp_provider', 'local');

            return $configured !== 'local' && is_a($configured, TrustedTimestampProvider::class, true)
                ? $app->make($configured)
                : $app->make(LocalClockTimestampProvider::class);
        });
    }

    /**
     * Load the module routes and the policy.
     */
    public function boot(): void
    {
        Gate::policy(PreservationPackage::class, PreservationPackagePolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
