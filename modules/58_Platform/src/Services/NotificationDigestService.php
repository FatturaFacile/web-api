<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\User;
use Modules\Platform\Models\NotificationDelivery;
use Modules\Platform\Models\NotificationDigestItem;
use Modules\Platform\Notifications\PlatformNotification;

/**
 * Sends the queued notifications as one summary per user and channel. Users who are silenced at that moment keep their
 * items for the next run.
 */
final class NotificationDigestService
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    /**
     * @return int number of digests sent
     */
    public function sendDue(): int
    {
        $sent = 0;
        $groups = NotificationDigestItem::query()->orderBy('created_at')->get()->groupBy(static fn (NotificationDigestItem $item): string => $item->user_id.'|'.$item->channel);

        foreach ($groups as $items) {
            $first = $items->first();
            $user = User::query()->find($first->user_id);

            if ($user === null) {
                NotificationDigestItem::query()->whereIn('id', $items->pluck('id'))->delete();

                continue;
            }

            if ($this->preferences->isSilenced($user)) {
                continue;
            }

            $lines = $items->map(static fn (NotificationDigestItem $item): string => '• '.$item->title)->implode("\n");
            $user->notify(new PlatformNotification('general', __('Notification digest (:count)', ['count' => $items->count()]), $lines, '/notifications', [$first->channel]));

            NotificationDelivery::query()->where('user_id', $user->id)->where('channel', $first->channel)->where('status', NotificationDelivery::QUEUED)
                ->update(['status' => NotificationDelivery::SENT, 'sent_at' => now()]);
            NotificationDigestItem::query()->whereIn('id', $items->pluck('id'))->delete();
            $sent++;
        }

        return $sent;
    }
}
