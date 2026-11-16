<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\User;
use Modules\Platform\Models\NotificationDelivery;
use Modules\Platform\Models\NotificationDigestItem;
use Modules\Platform\Notifications\PlatformNotification;

/**
 * Entry point to notify a user: applies their matrix (channel on/off, immediate or digest) and do-not-disturb settings.
 * Immediate channels are sent now; digest channels and channels held back by quiet hours / snooze are queued for the
 * daily digest. The in-app channel is never held. Every outcome ends up in the delivery register.
 */
final class NotificationDispatcher
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function send(User $user, string $category, string $title, ?string $body = null, ?string $url = null): void
    {
        /** @var list<string> $all */
        $all = config('platform.notification_channels');
        $plan = $this->preferences->deliveryFor($user, $category, $all);
        $enabled = $this->preferences->channelsFor($user, $category, $all);

        $immediate = $plan['immediate'];
        $held = array_values(array_diff($enabled, $immediate));

        // The in-app channel is cheap and silent: it is always delivered at once.
        if (in_array('database', $held, true)) {
            $immediate[] = 'database';
            $held = array_values(array_diff($held, ['database']));
        }

        foreach ($held as $channel) {
            NotificationDigestItem::query()->create(['user_id' => $user->id, 'channel' => $channel, 'category' => $category, 'title' => $title, 'body' => $body, 'deep_link' => $url]);
            NotificationDelivery::query()->create(['user_id' => $user->id, 'category' => $category, 'channel' => $channel, 'title' => $title, 'deep_link' => $url, 'status' => NotificationDelivery::QUEUED]);
        }

        if ($immediate !== []) {
            $user->notify(new PlatformNotification($category, $title, $body, $url, array_values(array_unique($immediate))));
        }
    }
}
