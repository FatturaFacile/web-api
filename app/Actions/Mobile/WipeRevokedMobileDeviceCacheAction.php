<?php

declare(strict_types=1);

namespace App\Actions\Mobile;

use App\Models\MobileDeviceSession;
use App\Services\Mobile\DeviceSessionService;

/** Command: WipeRevokedMobileDeviceCache (device-side acknowledgement of the wipe). */
final class WipeRevokedMobileDeviceCacheAction
{
    public function __construct(private readonly DeviceSessionService $devices) {}

    public function execute(MobileDeviceSession $device): MobileDeviceSession
    {
        if (! $device->is_revoked) {
            abort(409, 'Device session is not revoked; nothing to wipe.');
        }

        return $this->devices->acknowledgeWipe($device);
    }
}
