<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SHA-256 hash chain for the time audit ledger. Each entry includes the hash of its predecessor so
 * any post-hoc mutation of a record breaks all subsequent hashes (CJEU C-55/18 tamper-evidence).
 */
final class HashChainIntegrityService
{
    /**
     * The hash of the most recent audited entry for a tenant (the head of the chain).
     * Uses DB::table() directly to avoid triggering the immutability guards on TimeAuditEntry.
     */
    public function latestHash(string $tenantId): ?string
    {
        return DB::table('time_audit_entries')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('record_hash')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('record_hash');
    }

    /**
     * Compute the SHA-256 hash of an audit entry's canonical payload.
     *
     * @param  array<string, mixed>  $payload  Must contain: tenant_id, user_id, entity_type, entity_id,
     *                                         action, before_values, after_values, actor_id, occurred_at
     */
    public function computeHash(array $payload, ?string $prevHash): string
    {
        $occurredAt = (string) ($payload['occurred_at'] ?? '');
        if ($occurredAt !== '') {
            try {
                $occurredAt = CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Keep raw value when not a valid timestamp string
            }
        }

        $canonical = [
            'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
            'user_id' => (string) ($payload['user_id'] ?? ''),
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_id' => (string) ($payload['entity_id'] ?? ''),
            'action' => (string) ($payload['action'] ?? ''),
            'before_values' => $this->canonicalize($payload['before_values']),
            'after_values' => $this->canonicalize($payload['after_values']),
            'actor_id' => (string) ($payload['actor_id'] ?? ''),
            'occurred_at' => $occurredAt,
            'prev_hash' => $prevHash,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }

    /**
     * Walk every hashed audit entry for a tenant in chronological order and verify that each entry's
     * record_hash matches a fresh computation and that the prev_hash pointer is consistent.
     */
    public function verify(string $tenantId): HashChainVerificationResult
    {
        $entries = DB::table('time_audit_entries')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('record_hash')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $prevHash = null;
        $checked = 0;

        foreach ($entries as $row) {
            $payload = [
                'tenant_id' => $tenantId,
                'user_id' => $row->user_id,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'action' => $row->action,
                'before_values' => isset($row->before_values) ? json_decode($row->before_values, true) : null,
                'after_values' => isset($row->after_values) ? json_decode($row->after_values, true) : null,
                'actor_id' => $row->actor_id,
                'occurred_at' => $row->occurred_at,
            ];

            $expected = $this->computeHash($payload, $prevHash);

            if ($expected !== $row->record_hash || $row->prev_hash !== $prevHash) {
                return new HashChainVerificationResult(
                    verified: false,
                    entriesChecked: $checked,
                    brokenAtId: $row->id,
                );
            }

            $prevHash = $row->record_hash;
            $checked++;
        }

        return new HashChainVerificationResult(
            verified: true,
            entriesChecked: $checked,
            brokenAtId: null,
        );
    }
}
