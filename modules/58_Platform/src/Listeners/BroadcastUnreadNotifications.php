<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Modules\Platform\Events\UnreadNotificationsChanged;

/**
 * Turns every in-app (database) notification sent to a user into a realtime update of their unread counter.
 */
final class BroadcastUnreadNotifications
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $latest = $event->response instanceof DatabaseNotification ? $event->response : null;

        event(UnreadNotificationsChanged::for($event->notifiable, $latest));
    }
}
