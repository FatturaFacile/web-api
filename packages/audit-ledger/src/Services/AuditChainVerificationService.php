<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Services;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Collection;

final class AuditChainVerificationService
{
    /**
     * Verifies the cryptographic SHA-256 chain of records for a given tenant.
     *
     * @return array{
     *     valid: bool,
     *     total_verified: int,
     *     tampered_record_id: ?string,
     *     error_message: ?string
     * }
     */
    public function verifyTenantChain(string $tenantId): array
    {
        /** @var Collection<int, AuditLedgerEntry> $entries */
        $entries = AuditLedgerEntry::where('tenant_id', $tenantId)
            ->orderBy('event_timestamp', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($entries->isEmpty()) {
            return [
                'valid' => true,
                'total_verified' => 0,
                'tampered_record_id' => null,
                'error_message' => null,
            ];
        }

        $expectedPreviousHash = str_repeat('0', 64);
        $count = 0;

        foreach ($entries as $index => $entry) {
            // Verify link to previous entry
            if ($entry->previous_hash !== $expectedPreviousHash) {
                return [
                    'valid' => false,
                    'total_verified' => $count,
                    'tampered_record_id' => $entry->id,
                    'error_message' => "Chain break at entry {$entry->id}: previous_hash does not match preceding record_hash.",
                ];
            }

            // Recompute record hash deterministically
            $recalculatedHash = RecordAuditAction::computeRecordHash(
                previousHash: $entry->previous_hash,
                tenantId: (string) $entry->tenant_id,
                companyId: (string) $entry->company_id,
                timestampIso: $entry->event_timestamp->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                moduleCode: (string) $entry->module_code,
                action: (string) $entry->action,
                resourceType: (string) $entry->resource_type,
                resourceId: (string) $entry->resource_id,
                actorUserId: (string) ($entry->actor_user_id ?? 'SYSTEM'),
                payloadBefore: $entry->payload_before,
                payloadAfter: $entry->payload_after
            );

            if ($entry->record_hash !== $recalculatedHash) {
                return [
                    'valid' => false,
                    'total_verified' => $count,
                    'tampered_record_id' => $entry->id,
                    'error_message' => "Tampering detected at entry {$entry->id}: record_hash [{$entry->record_hash}] does not match recalculated hash [{$recalculatedHash}].",
                ];
            }

            $expectedPreviousHash = $entry->record_hash;
            $count++;
        }

        return [
            'valid' => true,
            'total_verified' => $count,
            'tampered_record_id' => null,
            'error_message' => null,
        ];
    }
}
