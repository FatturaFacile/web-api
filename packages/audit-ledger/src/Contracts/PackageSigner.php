<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Contracts;

/**
 * Signs the manifest of an export package. The application binds the document signature service (Mod 144); the default
 * signs with the HMAC key of the tenant chain.
 */
interface PackageSigner
{
    /**
     * @return string the signature, prefixed with the name of the signer ("hmac-sha256:...", "cades:...")
     */
    public function sign(string $tenantId, string $content): string;

    public function verify(string $tenantId, string $content, string $signature): bool;
}
