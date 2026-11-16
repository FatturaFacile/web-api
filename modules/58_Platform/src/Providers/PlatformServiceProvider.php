<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Console\PruneIdempotencyKeysCommand;
use Modules\Platform\Listeners\BroadcastUnreadNotifications;
use Modules\Platform\Listeners\LogNotificationDelivery;
use Modules\Platform\Services\NotificationDigestService;

/**
 * Cross-cutting platform features: idempotent mutating requests, ETag / If-Match optimistic locking (both middleware
 * are part of the `tenant` group, see bootstrap/app.php), the recycle bin and the in-app notification centre.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Event::listen(NotificationSent::class, BroadcastUnreadNotifications::class);
        Event::listen([NotificationSent::class, NotificationFailed::class], LogNotificationDelivery::class);

        // Realtime: clients authorise their private channel with the bearer token (POST /api/v1/broadcasting/auth).
        Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);
        Broadcast::channel('users.{id}.notifications', static fn (User $user, string $id): bool => (string) $user->id === $id);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneIdempotencyKeysCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PruneIdempotencyKeysCommand::class)->hourly()->withoutOverlapping()->onOneServer();
            $schedule->call(static fn (): int => app(NotificationDigestService::class)->sendDue())->name('platform:notification-digests')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
        });
    }
}
