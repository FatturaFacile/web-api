<?php

declare(strict_types=1);

namespace Modules\Integrations\Providers;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Integrations\Console\DispatchOverdueWebhooksCommand;
use Modules\Integrations\Console\PruneWebhookDeliveriesCommand;
use Modules\Integrations\Http\Middleware\AuthenticateApiKey;
use Modules\Integrations\Listeners\DeliverWebhooksOnDomainEventListener;
use Modules\Integrations\Webhooks\WebhookEventCatalog;

final class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/webhooks.php', 'webhooks');

    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('api.key', AuthenticateApiKey::class);

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchOverdueWebhooksCommand::class, PruneWebhookDeliveriesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(DispatchOverdueWebhooksCommand::class)->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command(PruneWebhookDeliveriesCommand::class)->dailyAt('03:45')->withoutOverlapping()->onOneServer();
        });

        $this->registerWebhookListeners();
    }

    /**
     * Outbound webhooks: each catalogue event is picked up once, on the channel it travels on (see WebhookEventCatalog).
     */
    private function registerWebhookListeners(): void
    {
        foreach (WebhookEventCatalog::all() as $name => $definition) {
            if ($definition['channel'] === WebhookEventCatalog::ChannelClass) {
                Event::listen($definition['event_class'], [DeliverWebhooksOnDomainEventListener::class, 'handle']);

                continue;
            }

            Event::listen($name, static function (array|DomainEventInterface $payload) use ($name): void {
                app(DeliverWebhooksOnDomainEventListener::class)->handleOutboxEvent($name, $payload);
            });
        }
    }
}
