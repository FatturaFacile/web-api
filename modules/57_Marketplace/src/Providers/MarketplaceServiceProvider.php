<?php

declare(strict_types=1);

namespace Modules\Marketplace\Providers;

use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use Modules\Marketplace\Connectors\BuiltInConnectors;
use Modules\Marketplace\Connectors\WebhookConnector;
use Modules\Marketplace\Console\CheckHealthCommand;
use Modules\Marketplace\Models\MarketplaceHealthCheck;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Policies\MarketplacePolicy;
use Modules\Marketplace\Support\ConnectorRegistry;

/**
 * Registers the connector catalog (the built-in connectors; other modules add theirs by resolving the registry and
 * calling register()), the routes, the policy and the health check command of the Marketplace module.
 */
final class MarketplaceServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings. The registry is shared so other modules can plug their connectors in.
     */
    public function register(): void
    {
        $this->app->singleton(ConnectorRegistry::class, function ($app): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            foreach (BuiltInConnectors::all($app->make(OutboundUrlGuard::class)) as $connector) {
                $registry->register($connector);
            }

            $registry->register(new WebhookConnector($app->make(WebhookUrlGuard::class), $app->make(WebhookSigner::class), $app->make(OutboundUrlGuard::class)));

            return $registry;
        });
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([MarketplaceInstallation::class, MarketplaceSyncRun::class, MarketplaceHealthCheck::class] as $model) {
            Gate::policy($model, MarketplacePolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckHealthCommand::class]);
        }
    }
}
