<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Hse\Domain\Contracts\HseNotificationGatewayInterface;
use Modules\Hse\Infrastructure\Notifications\HseAlertNotification;

/**
 * Notification & Alert Engine (Mod 008) adapter: stored notifications for the users, limited to the members of the tenant.
 */
final class NotificationEngineGateway implements HseNotificationGatewayInterface
{
    public function notify(string $tenantId, array $userIds, string $activity, array $payload): int
    {
        $users = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenantId))
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        Notification::send($users, new HseAlertNotification($activity, $payload));

        return $users->count();
    }
}
