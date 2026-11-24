<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationImmutableException;

/**
 * Write-once storage of the preservation archive. There is deliberately no delete and no overwrite: a path that exists
 * can only be read. Production should map the disk to a bucket with object lock.
 */
final class PreservationVault
{
    private function disk(): Filesystem
    {
        return Storage::disk((string) config('digital_preservation.disk'));
    }

    /**
     * Store a file once.
     *
     * @throws PreservationImmutableException when the path already exists
     */
    public function put(string $path, string $content): void
    {
        if ($this->disk()->exists($path)) {
            throw new PreservationImmutableException("The vault is write-once: {$path} already exists.");
        }

        $this->disk()->put($path, $content);
    }

    /**
     * Read a file, or null when it is missing.
     */
    public function get(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $content = $this->disk()->get($path);

        return is_string($content) ? $content : null;
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disk()->exists($path);
    }

    /**
     * Folder of one batch of a tenant.
     */
    public function batchFolder(string $tenantId, string $batchNumber): string
    {
        return "tenants/{$tenantId}/preservation/{$batchNumber}";
    }
}
