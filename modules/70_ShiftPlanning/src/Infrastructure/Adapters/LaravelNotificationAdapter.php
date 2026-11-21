<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use App\Models\User;
use Modules\HumanResources\Models\Employee;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Infrastructure\Notifications\ShiftPlanningNotification;

/**
 * Delivers through the notification system of the application (Mod 008): queued, database channel.
 */
final class LaravelNotificationAdapter implements NotificationPort
{
    public function notifyEmployee(string $tenantId, string $employeeId, string $translationKey, array $params = []): void
    {
        $userId = Employee::query()->whereKey($employeeId)->value('user_id');

        if (is_string($userId) && $userId !== '') {
            $this->notifyUser($tenantId, $userId, $translationKey, $params);
        }
    }

    public function notifyUser(string $tenantId, string $userId, string $translationKey, array $params = []): void
    {
        $user = User::query()->find($userId);

        $user?->notify(new ShiftPlanningNotification($tenantId, $translationKey, $params));
    }
}
