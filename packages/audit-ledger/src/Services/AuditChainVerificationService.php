<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Services;

use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Enterprise\AuditLedger\Domain\ValueObjects\VerificationResult;
use Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\ChainReader;

/**
 * Synchronous verification of a chain (kept for the callers of the first ledger). The queued, persisted verification of
 * Mod 005 is VerifyChainIntegrityHandler. Both use the IntegrityVerifier, which checks legacy SHA-256 entries and keyed
 * HMAC entries in one chain.
 */
final class AuditChainVerificationService
{
    public function __construct(
        private readonly IntegrityVerifier $verifier,
        private readonly ChainReader $reader,
    ) {}

    /**
     * Verifies the chain of a tenant, with the checkpoints as anchors.
     *
     * @return array{valid: bool, total_verified: int, tampered_record_id: ?string, error_message: ?string, outcome: string, broken_at_sequence: ?int, missing_range: ?array{from: int, to: int}}
     */
    public function verifyTenantChain(string $tenantId): array
    {
        $result = $this->verifier->verify($this->reader->entries($tenantId), $this->reader->anchors($tenantId));

        return $this->format($result, fn (int $sequence): ?string => AuditLedgerEntry::query()->where('tenant_id', $tenantId)->where('sequence', $sequence)->value('id'));
    }

    /**
     * Verifies the chain of the entries archived by one restore.
     *
     * @return array{valid: bool, total_verified: int, tampered_record_id: ?string, error_message: ?string, outcome: string, broken_at_sequence: ?int, missing_range: ?array{from: int, to: int}}
     */
    public function verifyArchivedChain(string $tenantId, string $restoreId): array
    {
        $result = $this->verifier->verify($this->reader->entries($tenantId, $restoreId));

        return $this->format($result, fn (int $sequence): ?string => AuditLedgerArchiveEntry::query()->where('tenant_id', $tenantId)->where('restore_id', $restoreId)->where('sequence', $sequence)->value('id'));
    }

    /**
     * @param  callable(int): ?string  $idOf
     * @return array{valid: bool, total_verified: int, tampered_record_id: ?string, error_message: ?string, outcome: string, broken_at_sequence: ?int, missing_range: ?array{from: int, to: int}}
     */
    private function format(VerificationResult $result, callable $idOf): array
    {
        $broken = $result->brokenAtSequence;

        return [
            'valid' => $result->isValid(),
            'total_verified' => $result->verifiedEntries,
            'tampered_record_id' => $broken !== null ? $idOf($broken) : null,
            'error_message' => $result->message,
            'outcome' => $result->outcome->value,
            'broken_at_sequence' => $broken,
            'missing_range' => $result->missingFromSequence === null ? null : ['from' => $result->missingFromSequence, 'to' => (int) $result->missingToSequence],
        ];
    }
}
