<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\TimeTravel\Services\CheckpointService;
use Modules\TimeTravel\Services\HistoryBackfiller;
use Modules\TimeTravel\Services\MerkleAuditService;
use Modules\TimeTravel\Support\Instant;

/**
 * Initializes the bi-temporal store of the tenants: loads the history that predates it, materializes a checkpoint per stream, seals the
 * closed audit periods with their Merkle root and verifies the hash chains. Safe to repeat.
 */
final class BackfillAndSnapshotCommand extends Command
{
    protected $signature = 'bitemporal:backfill-and-snapshot
        {--tenant= : Only this tenant id}
        {--skip-backfill : Do not load the history of the four domains}
        {--skip-checkpoints : Do not materialize the checkpoints}
        {--skip-seal : Do not seal the closed audit periods}
        {--verify : Verify the hash chains and the Merkle roots of the whole history and fail when they do not hold}';

    protected $description = 'Backfills the bi-temporal event store, materializes the checkpoints, seals the audit periods and verifies the Merkle chain';

    public function handle(HistoryBackfiller $backfiller, CheckpointService $checkpoints, MerkleAuditService $audit): int
    {
        $tenants = Tenant::query()
            ->when(is_string($this->option('tenant')) && $this->option('tenant') !== '', fn ($query) => $query->whereKey($this->option('tenant')))
            ->orderBy('id')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            $this->components->warn('No tenant to process.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($tenants as $tenantId) {
            $tenantId = (string) $tenantId;
            $this->components->info("Tenant {$tenantId}");

            if (! $this->option('skip-backfill')) {
                foreach ($backfiller->backfill($tenantId) as $stream => $count) {
                    $this->line("  backfill {$stream}: {$count} events");
                }
            }

            if (! $this->option('skip-checkpoints')) {
                foreach ($checkpoints->generateAll($tenantId) as $checkpoint) {
                    $this->line("  checkpoint {$checkpoint->aggregateType->value}: {$checkpoint->eventCount} events, root {$checkpoint->merkleRoot}");
                }
            }

            if (! $this->option('skip-seal')) {
                $this->line('  sealed periods: '.$audit->sealClosedPeriods($tenantId));
            }

            if ($this->option('verify')) {
                $proof = $audit->verify($tenantId, Instant::startOfDay('1970-01-01'), Instant::now());

                if ($proof->verified) {
                    $this->components->twoColumnDetail('  integrity', "verified, {$proof->eventsChecked} events");
                } else {
                    $failed = true;
                    $broken = $proof->brokenAt;
                    $this->components->error('  integrity BROKEN: '.($broken['reason'] ?? 'unknown').' at sequence '.($broken['sequence_number'] ?? 0));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
