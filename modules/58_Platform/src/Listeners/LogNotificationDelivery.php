<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Modules\Platform\Channels\PushChannel;
use Modules\Platform\Channels\TelegramChannel;
use Modules\Platform\Channels\WhatsAppChannel;
use Modules\Platform\Models\NotificationDelivery;
use Modules\Platform\Notifications\PlatformNotification;

/**
 * Writes one delivery-register row per channel of every PlatformNotification: sent or failed (with the error). A channel
 * that reports a failure is not also logged as sent.
 */
final class LogNotificationDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        if (! $event->notification instanceof PlatformNotification || ! $event->notifiable instanceof User) {
            return;
        }

        $channel = match ($event->channel) {
            PushChannel::class => 'push',
            TelegramChannel::class => 'telegram',
            WhatsAppChannel::class => 'whatsapp',
            default => (string) $event->channel,
        };
        $failed = $event instanceof NotificationFailed;
        $notification = $event->notification;
        $keys = ['notification_id' => $notification->id, 'channel' => $channel, 'user_id' => $event->notifiable->id];

        if (! $failed && NotificationDelivery::query()->where($keys)->where('status', NotificationDelivery::FAILED)->exists()) {
            return;
        }

        NotificationDelivery::query()->create($keys + [
            'category' => $notification->category,
            'title' => $notification->title,
            'deep_link' => $notification->url,
            'status' => $failed ? NotificationDelivery::FAILED : NotificationDelivery::SENT,
            'error' => $failed ? (string) ($event->data['message'] ?? 'Delivery failed') : null,
            'sent_at' => $failed ? null : now(),
        ]);
    }
}
