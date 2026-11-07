<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Enterprise\EventBus\Models\TransactionalOutboxArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxLifecycleService
{
    public function __construct(
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    /**
     * Archive dispatched outbox messages older than specified days. The relay marks them completed; rows written before
     * that carry the older processed status, so both are archived.
     * Non-destructive: copies to archive table before removing from active table.
     *
     * @return array{archived_count: int, older_than_days: int}
     */
    public function archiveProcessedMessages(int $olderThanDays = 30, int $batchSize = 500): array
    {
        $cutoffDate = now()->subDays($olderThanDays);

        $archivedCount = DB::transaction(function () use ($cutoffDate, $batchSize) {
            $messages = TransactionalOutbox::whereIn('status', TransactionalOutbox::DISPATCHED_STATUSES)
                ->where('processed_at', '<=', $cutoffDate)
                ->orderBy('processed_at')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($messages->isEmpty()) {
                return 0;
            }

            $archivePayload = [];
            $idsToDelete = [];
            $now = now();

            foreach ($messages as $msg) {
                $rawAttrs = $msg->getAttributes();
                $rawAttrs['archived_at'] = $now;
                $archivePayload[] = $rawAttrs;
                $idsToDelete[] = $msg->id;
            }

            TransactionalOutboxArchive::insert($archivePayload);
            TransactionalOutbox::whereIn('id', $idsToDelete)->delete();

            return count($idsToDelete);
        });

        if ($archivedCount > 0) {
            Log::info("OutboxLifecycleService: Archived {$archivedCount} processed messages older than {$olderThanDays} days.");

            $this->auditLedger?->record(
                action: 'OUTBOX_MESSAGES_ARCHIVED',
                model: new TransactionalOutboxArchive,
                payloadBefore: null,
                payloadAfter: [
                    'archived_count' => $archivedCount,
                    'cutoff_days' => $olderThanDays,
                    'cutoff_date' => $cutoffDate->toIso8601String(),
                ],
                moduleCode: 'FOUNDATION_EVENT_BUS'
            );
        }

        return [
            'archived_count' => $archivedCount,
            'older_than_days' => $olderThanDays,
        ];
    }

    /**
     * Purge archived messages exceeding retention policy.
     *
     * @return array{purged_count: int, retention_days: int}
     */
    public function purgeArchivedMessages(int $retentionDays = 90, int $batchSize = 500): array
    {
        $cutoffDate = now()->subDays($retentionDays);

        $purgedCount = DB::transaction(function () use ($cutoffDate, $batchSize) {
            $ids = TransactionalOutboxArchive::where('archived_at', '<=', $cutoffDate)
                ->orderBy('archived_at')
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                return 0;
            }

            TransactionalOutboxArchive::whereIn('id', $ids)->delete();

            return count($ids);
        });

        if ($purgedCount > 0) {
            Log::info("OutboxLifecycleService: Purged {$purgedCount} archived messages exceeding {$retentionDays} days retention.");

            $this->auditLedger?->record(
                action: 'OUTBOX_ARCHIVE_PURGED',
                model: new TransactionalOutboxArchive,
                payloadBefore: null,
                payloadAfter: [
                    'purged_count' => $purgedCount,
                    'retention_days' => $retentionDays,
                    'cutoff_date' => $cutoffDate->toIso8601String(),
                ],
                moduleCode: 'FOUNDATION_EVENT_BUS'
            );
        }

        return [
            'purged_count' => $purgedCount,
            'retention_days' => $retentionDays,
        ];
    }

    /**
     * Get real-time outbox health and lifecycle metrics.
     *
     * @return array<string, int>
     */
    public function getMetrics(): array
    {
        return [
            'pending_count' => TransactionalOutbox::where('status', TransactionalOutbox::STATUS_PENDING)->count(),
            'processing_count' => TransactionalOutbox::where('status', TransactionalOutbox::STATUS_PROCESSING)->count(),
            'processed_count' => TransactionalOutbox::whereIn('status', TransactionalOutbox::DISPATCHED_STATUSES)->count(),
            'failed_count' => TransactionalOutbox::where('status', TransactionalOutbox::STATUS_FAILED)->count(),
            'archived_count' => TransactionalOutboxArchive::count(),
        ];
    }
}
