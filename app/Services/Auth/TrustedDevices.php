<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Remember this device": after a successful second factor the client can ask for a trusted-device token, which lets
 * the next logins of that user skip the second factor until it expires. The token is random (256 bits), shown once and
 * stored as a SHA-256 hash; it is bound to the user, never to a tenant, and every change of the credentials of the user
 * (password, factors, revoked sessions) deletes all of them.
 */
final class TrustedDevices
{
    public const TOKEN_LENGTH = 64;

    public function enabled(): bool
    {
        return $this->days() > 0;
    }

    public function days(): int
    {
        return max(0, (int) config('auth.two_factor.trusted_device_days'));
    }

    /**
     * Remembers the device of the request and returns the plain token, which is not recoverable afterwards.
     *
     * @return array{token: string, device: TrustedDevice}
     */
    public function issue(User $user, Request $request, ?string $label = null): array
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $name = trim((string) $label);
        $name = $name !== '' ? $name : (DeviceDescription::from($request->userAgent()) ?? '');

        $device = TrustedDevice::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($token),
            'label' => $name === '' ? null : mb_substr($name, 0, 120),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($this->days()),
        ]);

        return ['token' => $token, 'device' => $device];
    }

    /**
     * The trusted device a token belongs to, or null when the token is unknown, expired or of another user. A use is
     * recorded on the device.
     */
    public function verify(User $user, string $token): ?TrustedDevice
    {
        if (! $this->enabled() || strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        /** @var TrustedDevice|null $device */
        $device = TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now()])->save();

        return $device;
    }

    /**
     * @return Collection<int, TrustedDevice>
     */
    public function list(User $user): Collection
    {
        return TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function revoke(User $user, string $id): bool
    {
        return TrustedDevice::query()->where('user_id', $user->getKey())->whereKey($id)->delete() > 0;
    }

    public function revokeAll(User $user): int
    {
        return TrustedDevice::query()->where('user_id', $user->getKey())->delete();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
