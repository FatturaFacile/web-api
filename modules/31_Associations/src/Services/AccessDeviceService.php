<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Support\Str;
use Modules\Associations\Models\AccessDevice;

/**
 * Readers and their secrets. A secret is shown once, when it is created, and only its hash stays.
 */
final class AccessDeviceService
{
    /**
     * @param  array{area_id: string, name: string, direction?: string}  $data
     * @return array{device: AccessDevice, secret: string}
     */
    public function register(array $data): array
    {
        $secret = Str::random(48);

        $device = AccessDevice::create([
            'area_id' => $data['area_id'],
            'name' => $data['name'],
            'direction' => $data['direction'] ?? 'in',
            'device_key' => Str::lower(Str::random(32)),
            'secret_hash' => hash('sha256', $secret),
        ]);

        return ['device' => $device, 'secret' => $secret];
    }

    public function rotateSecret(AccessDevice $device): string
    {
        $secret = Str::random(48);
        $device->update(['secret_hash' => hash('sha256', $secret)]);

        return $secret;
    }

    /**
     * Finds an active reader by its key and secret, in any tenant: the reader is what tells the tenant.
     */
    public function authenticate(string $key, string $secret): ?AccessDevice
    {
        /** @var ?AccessDevice $device */
        $device = AccessDevice::withoutGlobalScopes()->whereNull('deleted_at')->where('device_key', $key)->where('is_active', true)->first();

        if ($device === null || ! hash_equals($device->secret_hash, hash('sha256', $secret))) {
            return null;
        }

        return $device;
    }
}
