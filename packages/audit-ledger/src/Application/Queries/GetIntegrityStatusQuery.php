<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Queries;

use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Models\IntegrityVerificationRun;

/**
 * The integrity status of the chain of a tenant: the tail, the latest checkpoint, the run in progress and the latest
 * completed verification. `status` is one of never_verified, running, valid, broken_at, missing_range.
 */
final class GetIntegrityStatusQuery
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $tenantId): array
    {
        $tail = AuditLedgerEntry::query()->where('tenant_id', $tenantId)->whereNotNull('sequence')->orderByDesc('sequence')->first(['sequence', 'record_hash', 'event_timestamp']);
        $checkpoint = AuditCheckpoint::query()->where('tenant_id', $tenantId)->orderByDesc('sequence')->orderByDesc('created_at')->first();
        $running = IntegrityVerificationRun::query()->where('tenant_id', $tenantId)->whereIn('status', [IntegrityVerificationRun::STATUS_QUEUED, IntegrityVerificationRun::STATUS_RUNNING])->orderByDesc('created_at')->first();
        $last = IntegrityVerificationRun::query()->where('tenant_id', $tenantId)->where('status', IntegrityVerificationRun::STATUS_COMPLETED)->orderByDesc('finished_at')->first();

        $status = $running instanceof IntegrityVerificationRun
            ? 'running'
            : ($last instanceof IntegrityVerificationRun ? (string) $last->outcome : 'never_verified');

        return [
            'status' => $status,
            'tail' => $tail === null ? null : ['sequence' => (int) $tail->sequence, 'hash' => (string) $tail->record_hash],
            'running_run_id' => $running?->id,
            'last_run' => $last === null ? null : self::run($last),
            'last_checkpoint' => $checkpoint === null ? null : [
                'id' => $checkpoint->id,
                'type' => $checkpoint->type,
                'sequence' => $checkpoint->sequence,
                'hash' => $checkpoint->hash,
                'created_at' => $checkpoint->created_at->toIso8601String(),
                'anchored_at' => $checkpoint->anchored_at?->toIso8601String(),
                'anchor_uri' => $checkpoint->anchor_uri,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(IntegrityVerificationRun $run): array
    {
        return [
            'id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'outcome' => $run->outcome,
            'broken_at_sequence' => $run->broken_at_sequence,
            'missing_range' => $run->missing_from_sequence === null ? null : ['from' => $run->missing_from_sequence, 'to' => $run->missing_to_sequence],
            'verified_entries' => $run->verified_entries,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
