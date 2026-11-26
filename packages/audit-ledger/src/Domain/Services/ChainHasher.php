<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

use DateTimeInterface;
use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The hash of a ledger entry. Version 2 (every entry written since the forensic extension):
 *
 *     hash_n = HMAC-SHA256(K_tenant(key_version), canonical_json(entry_n) || hash_{n-1})
 *
 * starting from hash_0 = HMAC(K_tenant, "audit-genesis:" || tenant id). Version 1 entries (plain SHA-256, written before) are
 * still verified with the frozen legacy formula, so a chain can switch from 1 to 2 without a break.
 * The IP address, the user agent and the internal actor id are outside the hash: they are purged on their retention
 * schedule and never alter the proof.
 */
final class ChainHasher
{
    public const int LEGACY = 1;

    public const int KEYED = 2;

    public function __construct(private readonly AuditKeyring $keyring) {}

    /**
     * The fields covered by the version 2 hash.
     *
     * @param  array<string, mixed>|Model  $row
     * @return array<string, mixed>
     */
    public function entryFields(array|Model $row): array
    {
        $occurred = $this->value($row, 'event_timestamp') ?? $this->value($row, 'occurred_at');

        return [
            'tenant_id' => (string) $this->value($row, 'tenant_id'),
            'company_id' => (string) $this->value($row, 'company_id'),
            'sequence' => (int) $this->value($row, 'sequence'),
            'occurred_at' => $occurred instanceof DateTimeInterface ? CanonicalSerializer::timestamp($occurred) : CanonicalSerializer::timestamp(Carbon::parse((string) $occurred)),
            'category' => $this->value($row, 'category'),
            'module_code' => (string) $this->value($row, 'module_code'),
            'action' => (string) $this->value($row, 'action'),
            'resource_type' => (string) $this->value($row, 'resource_type'),
            'resource_id' => (string) $this->value($row, 'resource_id'),
            'actor_type' => (string) $this->value($row, 'actor_type'),
            'actor_ref' => $this->value($row, 'actor_ref'),
            'impersonator_ref' => $this->value($row, 'impersonator_ref'),
            'changes' => $this->decoded($this->value($row, 'changes')),
            'context' => $this->decoded($this->value($row, 'context')),
            'key_version' => (int) $this->value($row, 'key_version'),
        ];
    }

    /**
     * @param  array<string, mixed>|Model  $row
     */
    public function hashKeyed(array|Model $row, string $previousHash): string
    {
        $fields = $this->entryFields($row);

        return hash_hmac(
            'sha256',
            CanonicalSerializer::json($fields).$previousHash,
            $this->keyring->chainKey($fields['tenant_id'], $fields['key_version']),
        );
    }

    /**
     * @param  array<string, mixed>|Model  $row
     */
    public function hashLegacy(array|Model $row, string $previousHash): string
    {
        $occurred = $this->value($row, 'event_timestamp') ?? $this->value($row, 'occurred_at');
        $moment = $occurred instanceof DateTimeInterface ? Carbon::instance($occurred) : Carbon::parse((string) $occurred);

        return RecordAuditAction::computeRecordHash(
            previousHash: $previousHash,
            tenantId: (string) $this->value($row, 'tenant_id'),
            companyId: (string) $this->value($row, 'company_id'),
            timestampIso: $moment->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            moduleCode: (string) $this->value($row, 'module_code'),
            action: (string) $this->value($row, 'action'),
            resourceType: (string) $this->value($row, 'resource_type'),
            resourceId: (string) $this->value($row, 'resource_id'),
            actorUserId: (string) ($this->value($row, 'actor_user_id') ?? 'SYSTEM'),
            payloadBefore: $this->decoded($this->value($row, 'payload_before')),
            payloadAfter: $this->decoded($this->value($row, 'payload_after')),
        );
    }

    /**
     * The hash an entry must carry, whichever version it was written with.
     *
     * @param  array<string, mixed>|Model  $row
     */
    public function expectedHash(array|Model $row, string $previousHash): string
    {
        return (int) ($this->value($row, 'hash_version') ?? self::LEGACY) >= self::KEYED
            ? $this->hashKeyed($row, $previousHash)
            : $this->hashLegacy($row, $previousHash);
    }

    /**
     * The hash the very first entry of a chain links to.
     *
     * @param  array<string, mixed>|Model  $firstRow
     */
    public function genesisFor(array|Model $firstRow): string
    {
        if ((int) ($this->value($firstRow, 'hash_version') ?? self::LEGACY) < self::KEYED) {
            return str_repeat('0', 64);
        }

        return $this->keyring->genesisHash((string) $this->value($firstRow, 'tenant_id'), (int) $this->value($firstRow, 'key_version'));
    }

    /**
     * hash_0 of a tenant that has no entry yet, for the key version in force.
     */
    public function genesisForTenant(string $tenantId): string
    {
        return $this->keyring->genesisHash($tenantId, $this->keyring->activeVersion($tenantId));
    }

    /**
     * @param  array<string, mixed>|Model  $row
     */
    private function value(array|Model $row, string $key): mixed
    {
        return $row instanceof Model ? $row->getAttribute($key) : ($row[$key] ?? null);
    }

    private function decoded(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);

            return $decoded === null ? $value : $decoded;
        }

        return $value === '' ? null : $value;
    }
}
