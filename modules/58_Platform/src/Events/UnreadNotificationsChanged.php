<?php

declare(strict_types=1);

namespace Modules\Platform\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Pushed on the private channel of a user whenever their in-app notifications change (new one received, read, all read,
 * deleted) so the web and desktop clients update the unread counter without reloading.
 */
final class UnreadNotificationsChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>|null  $latest  the notification that triggered the update, if any
     */
    public function __construct(public readonly string $userId, public readonly int $unreadCount, public readonly ?array $latest = null) {}

    public static function for(User $user, ?DatabaseNotification $latest = null): self
    {
        return new self((string) $user->id, $user->unreadNotifications()->count(), $latest === null ? null : [
            'id' => $latest->id,
            'type' => $latest->type,
            'data' => $latest->data,
            'created_at' => $latest->created_at?->toIso8601String(),
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}.notifications");
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    /**
     * @return array{unread_count: int, notification: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        return ['unread_count' => $this->unreadCount, 'notification' => $this->latest];
    }
}
