<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Drivers\TenantTimestampProvider;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Policies\PreservationPackagePolicy;

/**
 * Registers the routes, the policy and the time-stamp provider of the Document Trust module.
 */
final class DocumentTrustServiceProvider extends ServiceProvider
{
    /**
     * Bind the time-stamp provider: the RFC 3161 authority of the tenant when configured, otherwise
     * `services.preservation.timestamp_provider` (`local`, the server clock, which is not a qualified time stamp, or the
     * class name of a provider that implements TrustedTimestampProvider).
     */
    public function register(): void
    {
        $this->app->bind(TrustedTimestampProvider::class, TenantTimestampProvider::class);
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
