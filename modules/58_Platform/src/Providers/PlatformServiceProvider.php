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
use Modules\Inventory\Events\StockBelowMinimumEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Platform\Console\PruneIdempotencyKeysCommand;
use Modules\Platform\Listeners\BroadcastUnreadNotifications;
use Modules\Platform\Listeners\LogNotificationDelivery;
use Modules\Platform\Listeners\NotifyBusinessEvents;
use Modules\Platform\Localization\Providers\LocalizationServiceProvider;
use Modules\Platform\Services\NotificationDigestService;
use Modules\SDI\Events\SdiInvoiceRejected;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;

/**
 * Cross-cutting platform features: idempotent mutating requests, ETag / If-Match optimistic locking (both middleware
 * are part of the `tenant` group, see bootstrap/app.php), the recycle bin and the in-app notification centre.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(LocalizationServiceProvider::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Event::listen(NotificationSent::class, BroadcastUnreadNotifications::class);
        Event::listen([NotificationSent::class, NotificationFailed::class], LogNotificationDelivery::class);

        Event::listen(InvoicePaidEvent::class, [NotifyBusinessEvents::class, 'handleInvoicePaid']);
        Event::listen(SdiInvoiceRejected::class, [NotifyBusinessEvents::class, 'handleSdiRejected']);
        Event::listen(StockBelowMinimumEvent::class, [NotifyBusinessEvents::class, 'handleStockBelowMinimum']);
        Event::listen(ApprovalTokenGeneratedEvent::class, [NotifyBusinessEvents::class, 'handleApprovalPending']);

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
