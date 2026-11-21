<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

/**
 * Notification & Alert Engine (Mod 008): delivers an HSE alert to the HR manager, the RSPP/SiBe and the line manager.
 */
interface HseNotificationGatewayInterface
{
    /**
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $payload
     * @return int the number of recipients notified
     */
    public function notify(string $tenantId, array $userIds, string $activity, array $payload): int;
}
