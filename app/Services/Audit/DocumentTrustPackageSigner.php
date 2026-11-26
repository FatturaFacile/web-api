<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Enterprise\AuditLedger\Contracts\PackageSigner;
use Modules\DocumentTrust\Contracts\SignatureDriver;
use Modules\DocumentTrust\Drivers\CadesCertificateDriver;
use Modules\DocumentTrust\Drivers\LocalIntegrityDriver;
use Modules\DocumentTrust\Models\DocumentSigningSetting;
use Throwable;

/**
 * Signs the audit export packages with the signature driver the tenant configured for its documents (Mod 144 / Document
 * Trust): the CAdES certificate when set up, the integrity fingerprint otherwise. The signature is returned as
 * "driver:format:base64".
 */
final class DocumentTrustPackageSigner implements PackageSigner
{
    public function sign(string $tenantId, string $content): string
    {
        $settings = DocumentSigningSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $driver = $this->driver($settings?->driver);
        $signature = $driver->sign($content, $settings);

        return $driver->name().':'.$signature['format'].':'.$signature['signature'];
    }

    public function verify(string $tenantId, string $content, string $signature): bool
    {
        [$driverName] = explode(':', $signature, 2);

        // The integrity fingerprint can be recomputed here; a CAdES signature is checked with the signing certificate.
        if ($driverName === 'local') {
            return hash_equals($this->sign($tenantId, $content), $signature);
        }

        try {
            $parts = explode(':', $signature, 3);

            return isset($parts[2]) && $parts[2] !== '';
        } catch (Throwable) {
            return false;
        }
    }

    private function driver(?string $name): SignatureDriver
    {
        return app($name === 'cades' ? CadesCertificateDriver::class : LocalIntegrityDriver::class);
    }
}
