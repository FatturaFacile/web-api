<?php

declare(strict_types=1);

namespace Modules\Visitors\Services;

use Illuminate\Support\Str;
use Modules\Visitors\Models\VisitorKiosk;

/**
 * Reception kiosks and their secrets. A secret is shown once, when it is created or rotated, and only its hash stays.
 */
final class VisitorKioskService
{
    /**
     * @return array{kiosk: VisitorKiosk, secret: string}
     */
    public function register(string $name): array
    {
        $secret = Str::random(48);

        $kiosk = VisitorKiosk::query()->create([
            'name' => $name,
            'device_key' => Str::lower(Str::random(32)),
            'secret_hash' => hash('sha256', $secret),
        ]);

        return ['kiosk' => $kiosk, 'secret' => $secret];
    }

    /**
     * Rotate secret.
     */
    public function rotateSecret(VisitorKiosk $kiosk): string
    {
        $secret = Str::random(48);
        $kiosk->update(['secret_hash' => hash('sha256', $secret)]);

        return $secret;
    }

    /**
     * Finds an active kiosk by its key and secret, in any tenant: the kiosk is what tells the tenant.
     */
    public function authenticate(string $key, string $secret): ?VisitorKiosk
    {
        if ($key === '' || $secret === '') {
            return null;
        }

        /** @var ?VisitorKiosk $kiosk */
        $kiosk = VisitorKiosk::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('device_key', $key)->where('is_active', true)->first();

        if ($kiosk === null || ! hash_equals($kiosk->secret_hash, hash('sha256', $secret))) {
            return null;
        }

        return $kiosk;
    }
}
