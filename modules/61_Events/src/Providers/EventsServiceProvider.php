<?php

declare(strict_types=1);

namespace Modules\Events\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\EventSession;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Models\ManagedEvent;
use Modules\Events\Policies\EventsPolicy;

/**
 * Registers the routes and the policy of the Events (event management) module.
 */
final class EventsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([ManagedEvent::class, EventSession::class, EventTicketType::class, EventRegistration::class] as $model) {
            Gate::policy($model, EventsPolicy::class);
        }
    }
}
