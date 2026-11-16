<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\MobileDeviceSession;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceSessionService
{
    public function __construct(private readonly MobilePushService $push) {}

    /**
     * Register (or re-activate after a fresh authentication) a device session.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(User $user, string $tenantId, array $data, ?int $accessTokenId = null): MobileDeviceSession
    {
        $device = MobileDeviceSession::withoutGlobalScopes()->firstOrNew([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'device_id' => $data['device_id'],
        ]);

        $device->fill([
            'device_name' => $data['device_name'] ?? $device->device_name,
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? $device->app_version,
            'biometric_enrolled' => (bool) ($data['biometric_enrolled'] ?? $device->biometric_enrolled ?? false),
            'last_active_at' => now(),
            // a fresh authenticated registration lifts a previous revocation
            'is_revoked' => false,
            'revoked_at' => null,
            'revoke_reason' => null,
            'wipe_requested_at' => null,
            'wipe_acknowledged_at' => null,
        ]);

        if ($accessTokenId !== null) {
            $device->access_token_id = $accessTokenId;
        }

        if (! empty($data['push_token'])) {
            $device->push_token = $data['push_token'];
            $device->push_provider = $data['push_provider'] ?? ($device->platform === MobileDeviceSession::PLATFORM_IOS ? 'apns' : 'fcm');
        }

        $device->save();

        return $device;
    }

    /** Revoke a device: access is cut immediately, the local cache must be wiped on next contact. */
    public function revoke(MobileDeviceSession $device, string $reason): MobileDeviceSession
    {
        $device->forceFill([
            'is_revoked' => true,
            'revoked_at' => now(),
            'revoke_reason' => $reason,
            'wipe_requested_at' => now(),
            'wipe_acknowledged_at' => null,
        ])->save();

        $this->push->sendWipe($device);

        return $device;
    }

    /**
     * Revoke every device of a user (offboarding). Tokens that are not bound to a
     * device are deleted straight away.
     */
    public function revokeAllForUser(string $userId, string $reason): int
    {
        $count = 0;
        $boundTokenIds = [];

        MobileDeviceSession::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('is_revoked', false)
            ->each(function (MobileDeviceSession $device) use ($reason, &$count, &$boundTokenIds): void {
                if ($device->access_token_id !== null) {
                    $boundTokenIds[] = $device->access_token_id;
                }
                $this->revoke($device, $reason);
                $count++;
            });

        PersonalAccessToken::where('tokenable_id', $userId)
            ->where('tokenable_type', (new User)->getMorphClass())
            ->when($boundTokenIds !== [], fn ($q) => $q->whereNotIn('id', $boundTokenIds))
            ->delete();

        return $count;
    }

    /** The device confirmed the local wipe: drop its token for good. */
    public function acknowledgeWipe(MobileDeviceSession $device): MobileDeviceSession
    {
        $device->forceFill(['wipe_acknowledged_at' => now()])->save();

        if ($device->access_token_id !== null) {
            PersonalAccessToken::whereKey($device->access_token_id)->delete();
        }

        $device->manifests()->withoutGlobalScopes()->delete();

        return $device;
    }
}
