<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Console\Commands;

use Enterprise\EventBus\Services\OutboxLifecycleService;
use Illuminate\Console\Command;

final class PruneOutboxCommand extends Command
{
    protected $signature = 'event-bus:outbox-prune
                            {--archive-days=30 : Days after which processed messages are moved to archive}
                            {--retention-days=90 : Days after which archived messages are permanently purged}
                            {--batch-size=500 : Batch size for database chunking}';

    protected $description = 'Archive processed transactional outbox messages and purge expired archives according to retention policy';

    public function handle(OutboxLifecycleService $lifecycleService): int
    {
        $archiveDays = (int) $this->option('archive-days');
        $retentionDays = (int) $this->option('retention-days');
        $batchSize = (int) $this->option('batch-size');

        $this->info('Starting Transactional Outbox Lifecycle Maintenance...');

        // 1. Archive step
        $archiveResult = $lifecycleService->archiveProcessedMessages($archiveDays, $batchSize);
        $this->line("Archived {$archiveResult['archived_count']} messages older than {$archiveResult['older_than_days']} days.");

        // 2. Purge step
        $purgeResult = $lifecycleService->purgeArchivedMessages($retentionDays, $batchSize);
        $this->line("Purged {$purgeResult['purged_count']} archive records older than {$purgeResult['retention_days']} days.");

        // 3. Metrics report
        $metrics = $lifecycleService->getMetrics();
        $this->table(
            ['Pending', 'Processing', 'Processed', 'Failed', 'Archived'],
            [[$metrics['pending_count'], $metrics['processing_count'], $metrics['processed_count'], $metrics['failed_count'], $metrics['archived_count']]]
        );

        $this->info('Transactional Outbox Lifecycle Maintenance completed successfully.');

        return self::SUCCESS;
    }
}
