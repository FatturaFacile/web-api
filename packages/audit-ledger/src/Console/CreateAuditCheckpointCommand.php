<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Console;

use Enterprise\AuditLedger\Application\Commands\CreateCheckpointCommand;
use Enterprise\AuditLedger\Application\Commands\CreateCheckpointHandler;
use Enterprise\AuditLedger\Jobs\CreateDailyCheckpointsJob;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Illuminate\Console\Command;

/**
 * Takes a checkpoint of the audit chain: of one tenant (a daily one, or the close of an accounting period) or the daily
 * checkpoints of every active tenant.
 */
final class CreateAuditCheckpointCommand extends Command
{
    protected $signature = 'audit-ledger:checkpoint {--tenant= : Tenant id; every active tenant when omitted} {--period= : Accounting period reference (e.g. 2027-12): takes a period checkpoint}';

    protected $description = 'Pin the tail of the audit chain with a signed checkpoint (daily or accounting period)';

    public function handle(CreateCheckpointHandler $handler): int
    {
        $tenantId = $this->option('tenant');

        if (! is_string($tenantId) || $tenantId === '') {
            CreateDailyCheckpointsJob::dispatchSync();
            $this->info('Daily checkpoints taken.');

            return self::SUCCESS;
        }

        $period = $this->option('period');
        $checkpoint = $handler->handle(new CreateCheckpointCommand(
            $tenantId,
            is_string($period) && $period !== '' ? AuditCheckpoint::TYPE_PERIOD : AuditCheckpoint::TYPE_DAILY,
            is_string($period) && $period !== '' ? $period : null,
        ));

        $this->info($checkpoint === null ? 'The tenant has no audit entry yet.' : "Checkpoint {$checkpoint->id} at sequence {$checkpoint->sequence}.");

        return self::SUCCESS;
    }
}
