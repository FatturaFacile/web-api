<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\MobileDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated Sanctum token to its mobile device session.
 *
 * A revoked device (lost phone, employee offboarding) is rejected on every API
 * route with `X-Mobile-Wipe: required`, except the wipe acknowledgement.
 */
class EnsureMobileDeviceActive
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $device = MobileDeviceSession::withoutGlobalScopes()
            ->where('access_token_id', $token->getKey())
            ->first();

        if ($device === null) {
            return $next($request);
        }

        $request->attributes->set('mobile_device', $device);

        if ($device->is_revoked) {
            if ($request->routeIs('mobile.devices.wipe-ack')) {
                return $next($request);
            }

            return $this->problemDetailsResponse(
                title: 'Device Revoked',
                detail: 'This device has been revoked. Wipe the local cache and sign in again.',
                status: Response::HTTP_UNAUTHORIZED,
            )->withHeaders(['X-Mobile-Wipe' => $device->needsWipe() ? 'required' : 'done']);
        }

        if ($device->last_active_at === null || $device->last_active_at->lt(now()->subMinute())) {
            $device->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
