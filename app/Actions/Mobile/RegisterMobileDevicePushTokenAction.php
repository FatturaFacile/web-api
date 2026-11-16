<?php

declare(strict_types=1);

namespace App\Actions\Mobile;

use App\Models\MobileDeviceSession;

/** Command: RegisterMobileDevicePushToken */
final class RegisterMobileDevicePushTokenAction
{
    public function execute(MobileDeviceSession $device, string $token, ?string $provider = null, ?string $appVersion = null): MobileDeviceSession
    {
        $device->forceFill([
            'push_token' => $token,
            'push_provider' => $provider ?? ($device->platform === MobileDeviceSession::PLATFORM_IOS ? 'apns' : 'fcm'),
            'app_version' => $appVersion ?? $device->app_version,
            'last_active_at' => now(),
        ])->save();

        return $device;
    }
}
