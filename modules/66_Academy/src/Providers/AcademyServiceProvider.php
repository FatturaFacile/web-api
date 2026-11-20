<?php

declare(strict_types=1);

namespace Modules\Academy\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Academy\Listeners\EvaluateMissionsOnDomainEvent;

/**
 * Registers the routes and the domain event listener of the Academy module.
 */
final class AcademyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen('*', static function (string $eventName, array $payload): void {
            app(EvaluateMissionsOnDomainEvent::class)->handle($eventName, $payload);
        });
    }
}
