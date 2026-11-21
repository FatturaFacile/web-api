<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

/**
 * Push and in-app notifications (Mod 008). Implementations queue the delivery; the planner never waits for it.
 */
interface NotificationPort
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function notifyEmployee(string $tenantId, string $employeeId, string $translationKey, array $params = []): void;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function notifyUser(string $tenantId, string $userId, string $translationKey, array $params = []): void;
}
