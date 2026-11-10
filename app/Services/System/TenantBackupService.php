<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Services\Backup\BackupArchiveSigner;
use App\Services\Backup\TenantBackupService as BaseTenantBackupService;
use App\Support\Security\KeyDerivation;

/**
 * System Tenant Backup Service with HKDF-SHA256 subkey derivation for manifest signing
 * and backward-compatible verification against legacy `app.key` / `BackupArchiveSigner` signatures.
 */
class TenantBackupService extends BaseTenantBackupService
{
    public const string HKDF_CONTEXT = 'system.tenant_backup.manifest.v1';

    public function __construct(?BackupArchiveSigner $signer = null)
    {
        parent::__construct($signer ?? app(BackupArchiveSigner::class));
    }

    /**
     * Sign a backup manifest using an HKDF-derived subkey (`KeyDerivation::derive`).
     */
    public function signManifest(string $manifest): string
    {
        $derivedKey = KeyDerivation::derive(self::HKDF_CONTEXT);

        return hash_hmac('sha256', $manifest, $derivedKey);
    }

    /**
     * Verify a backup manifest signature against the HKDF-derived subkey, with backward-compatible
     * fallback to legacy raw `config('app.key')` and `BackupArchiveSigner`.
     */
    public function verifyManifest(string $manifest, ?string $signature): bool
    {
        $normalized = strtolower(trim((string) $signature));
        if (preg_match('/^[a-f0-9]{64}$/', $normalized) !== 1) {
            return false;
        }

        $hkdfSignature = hash_hmac('sha256', $manifest, KeyDerivation::derive(self::HKDF_CONTEXT));
        if (hash_equals($hkdfSignature, $normalized)) {
            return true;
        }

        $legacyRawKeySignature = hash_hmac('sha256', $manifest, KeyDerivation::rawAppKey());
        if (hash_equals($legacyRawKeySignature, $normalized)) {
            return true;
        }

        return app(BackupArchiveSigner::class)->verify($manifest, $normalized);
    }
}
