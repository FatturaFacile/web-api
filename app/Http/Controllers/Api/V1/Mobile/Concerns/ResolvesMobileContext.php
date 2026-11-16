<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile\Concerns;

use App\Models\MobileDeviceSession;
use App\Models\User;
use App\Services\Mobile\RoleResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait ResolvesMobileContext
{
    protected function currentDevice(Request $request): ?MobileDeviceSession
    {
        $device = $request->attributes->get('mobile_device');
        if ($device instanceof MobileDeviceSession) {
            return $device;
        }

        $deviceId = $request->header('X-Device-Id');
        if ($deviceId === null || $request->user() === null) {
            return null;
        }

        return MobileDeviceSession::where('user_id', $request->user()->id)->where('device_id', $deviceId)->first();
    }

    protected function authorizeAdmin(Request $request, string $permission): void
    {
        /** @var User $user */
        $user = $request->user();
        $roles = app(RoleResolver::class);

        if (! $roles->isAdmin($user) && ! $roles->allows($user, $permission)) {
            throw new AccessDeniedHttpException('This action requires administrator rights.');
        }
    }
}
