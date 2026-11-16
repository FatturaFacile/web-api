<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Contracts\MobilePushGateway;
use App\Models\MobileDeviceSession;

final class MobilePushService
{
    public function __construct(private readonly MobilePushGateway $gateway) {}

    /**
     * Push to every active device of the user. Returns the number of devices reached.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = [], array $options = []): int
    {
        $sent = 0;

        MobileDeviceSession::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('is_revoked', false)
            ->whereNotNull('push_token')
            ->each(function (MobileDeviceSession $device) use (&$sent, $title, $body, $data, $options): void {
                if ($this->gateway->send($device->push_provider ?? 'fcm', (string) $device->push_token, $title, $body, $data, $options)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /** Silent push asking a (revoked) device to wipe its local cache. */
    public function sendWipe(MobileDeviceSession $device): bool
    {
        if ($device->push_token === null) {
            return false;
        }

        return $this->gateway->send(
            $device->push_provider ?? 'fcm',
            (string) $device->push_token,
            '',
            '',
            ['type' => 'wipe_cache', 'device_id' => $device->device_id],
            ['silent' => true],
        );
    }
}
