<?php

declare(strict_types=1);

namespace Modules\Documents\Providers;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Services\TenantAiConsent;

/**
 * Registers the routes and bindings of the Documents module.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    /**
     * Register the module bindings.
     */
    public function register(): void
    {
        $this->app->singleton(TenantAiConsentInterface::class, TenantAiConsent::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
