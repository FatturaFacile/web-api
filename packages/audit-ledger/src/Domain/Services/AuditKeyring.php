<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

use Closure;
use RuntimeException;

/**
 * Derives the per-tenant keys of the ledger from the configured master keys: K_tenant = HKDF-SHA256(master[version],
 * info, salt = tenant id). Master keys live in configuration or a secret manager, never in the database; the key
 * version of every entry is part of the entry, so entries signed with an old version keep verifying after a rotation.
 */
final class AuditKeyring
{
    private const string INFO_CHAIN = 'fatturafacile/audit-ledger/chain/v1';

    private const string INFO_PSEUDONYM = 'fatturafacile/audit-ledger/pseudonym/v1';

    /** @var array<string, int> */
    private array $tenantVersions = [];

    /**
     * @param  array<int|string, string>  $masterKeys  key version => secret (a base64: prefix is decoded)
     * @param  Closure(string): ?int|null  $tenantVersionResolver  the key version activated for a tenant, if any
     */
    public function __construct(
        private readonly array $masterKeys,
        private readonly int $defaultVersion = 1,
        private readonly ?Closure $tenantVersionResolver = null,
        private readonly ?string $fallbackSecret = null,
    ) {}

    public static function fromConfig(?Closure $tenantVersionResolver = null): self
    {
        $fallback = config('audit_ledger.allow_app_key_fallback') ? (string) config('app.key') : null;

        return new self(
            (array) config('audit_ledger.master_keys', []),
            max(1, (int) config('audit_ledger.active_key_version', 1)),
            $tenantVersionResolver,
            $fallback !== '' ? $fallback : null,
        );
    }

    /**
     * The key version a new entry of the tenant is signed with.
     */
    public function activeVersion(string $tenantId): int
    {
        if (isset($this->tenantVersions[$tenantId])) {
            return $this->tenantVersions[$tenantId];
        }

        $resolved = $this->tenantVersionResolver !== null ? ($this->tenantVersionResolver)($tenantId) : null;

        return $this->tenantVersions[$tenantId] = max($this->defaultVersion, (int) $resolved);
    }

    public function forgetTenant(string $tenantId): void
    {
        unset($this->tenantVersions[$tenantId]);
    }

    public function hasVersion(int $version): bool
    {
        return isset($this->masterKeys[$version]) || isset($this->masterKeys[(string) $version]) || $this->fallbackSecret !== null;
    }

    /**
     * The 32 byte HMAC key of the hash chain of a tenant.
     */
    public function chainKey(string $tenantId, int $version): string
    {
        return $this->derive($tenantId, $version, self::INFO_CHAIN);
    }

    /**
     * The 32 byte key of the pseudonym tokens of a tenant.
     */
    public function pseudonymKey(string $tenantId, int $version): string
    {
        return $this->derive($tenantId, $version, self::INFO_PSEUDONYM);
    }

    /**
     * hash_0: the value the first entry of a version 2 chain links to.
     */
    public function genesisHash(string $tenantId, int $version): string
    {
        return hash_hmac('sha256', 'audit-genesis:'.$tenantId, $this->chainKey($tenantId, $version));
    }

    private function derive(string $tenantId, int $version, string $info): string
    {
        return hash_hkdf('sha256', $this->master($version), 32, $info, $tenantId);
    }

    private function master(int $version): string
    {
        $secret = $this->masterKeys[$version] ?? $this->masterKeys[(string) $version] ?? null;

        if ($secret === null || $secret === '') {
            if ($this->fallbackSecret === null) {
                throw new RuntimeException("The audit ledger master key for version {$version} is not configured (AUDIT_LEDGER_MASTER_KEY).");
            }

            // Development and tests: derive a distinct master per version from the application key.
            return hash('sha256', 'audit-ledger-fallback:'.$version.':'.$this->decode($this->fallbackSecret), true);
        }

        return $this->decode($secret);
    }

    private function decode(string $secret): string
    {
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            return $decoded === false ? $secret : $decoded;
        }

        return $secret;
    }
}
