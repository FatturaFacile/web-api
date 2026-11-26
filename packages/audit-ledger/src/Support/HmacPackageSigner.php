<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Enterprise\AuditLedger\Contracts\PackageSigner;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;

/**
 * Default package signer: HMAC-SHA256 with the chain key of the tenant (key version 1 for the signature domain, so the
 * signature stays verifiable whatever the key version of the chain is).
 */
final class HmacPackageSigner implements PackageSigner
{
    public function __construct(private readonly AuditKeyring $keyring) {}

    public function sign(string $tenantId, string $content): string
    {
        return 'hmac-sha256:'.hash_hmac('sha256', $content, $this->keyring->chainKey($tenantId, 1));
    }

    public function verify(string $tenantId, string $content, string $signature): bool
    {
        return hash_equals($this->sign($tenantId, $content), $signature);
    }
}
