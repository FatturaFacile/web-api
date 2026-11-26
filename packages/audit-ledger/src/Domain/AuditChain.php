<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain;

use DateTimeInterface;
use Enterprise\AuditLedger\Domain\Services\ChainHasher;
use Illuminate\Support\Carbon;

/**
 * Aggregate root of the audit chain of one tenant. It knows the tail of the chain (last sequence, hash and time) and
 * guarantees the invariants of the next entry: the sequence is the previous one plus one, the entry links to the hash of
 * the previous one (or to hash_0 for an empty chain), time never goes backwards and the entry is signed with the key
 * version in force. The caller holds the per-tenant lock while the chain is resumed and extended.
 */
final class AuditChain
{
    private function __construct(
        private readonly string $tenantId,
        private int $lastSequence,
        private ?string $lastHash,
        private ?Carbon $lastOccurredAt,
        private readonly ChainHasher $hasher,
    ) {}

    public static function resume(string $tenantId, ChainHasher $hasher, ?int $lastSequence = null, ?string $lastHash = null, ?DateTimeInterface $lastOccurredAt = null): self
    {
        return new self($tenantId, (int) $lastSequence, $lastHash, $lastOccurredAt === null ? null : Carbon::instance($lastOccurredAt), $hasher);
    }

    public function lastSequence(): int
    {
        return $this->lastSequence;
    }

    public function lastHash(): ?string
    {
        return $this->lastHash;
    }

    /**
     * Appends an entry to the chain and returns its complete row (sequence, time, key version, links and hash).
     *
     * @param  array<string, mixed>  $fields  tenant_id, company_id, category, module_code, action, resource_type, resource_id, actor_type,
     *                                        actor_ref, impersonator_ref, changes, context
     * @return array<string, mixed>
     */
    public function append(array $fields, int $keyVersion, ?Carbon $now = null): array
    {
        $occurredAt = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');

        if ($this->lastOccurredAt !== null && $occurredAt->lessThanOrEqualTo($this->lastOccurredAt)) {
            $occurredAt = $this->lastOccurredAt->copy()->setTimezone('UTC')->addMicrosecond();
        }

        $row = array_merge($fields, [
            'tenant_id' => $this->tenantId,
            'sequence' => $this->lastSequence + 1,
            'event_timestamp' => $occurredAt,
            'key_version' => $keyVersion,
            'hash_version' => ChainHasher::KEYED,
        ]);

        $previous = $this->lastHash ?? $this->hasher->genesisForTenant($this->tenantId);
        $row['previous_hash'] = $previous;
        $row['record_hash'] = $this->hasher->hashKeyed($row, $previous);

        $this->lastSequence = (int) $row['sequence'];
        $this->lastHash = $row['record_hash'];
        $this->lastOccurredAt = $occurredAt;

        return $row;
    }
}
