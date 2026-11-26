<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Application\Commands\CreateCheckpointCommand;
use Enterprise\AuditLedger\Application\Commands\CreateCheckpointHandler;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Takes the daily checkpoint of every tenant that wrote to its chain in the last 24 hours. A failure of one tenant is
 * reported and never stops the others.
 */
final class CreateDailyCheckpointsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(CreateCheckpointHandler $handler): void
    {
        $tenants = AuditLedgerEntry::query()
            ->where('event_timestamp', '>=', Carbon::now('UTC')->subDay())
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            try {
                $handler->handle(new CreateCheckpointCommand((string) $tenantId, AuditCheckpoint::TYPE_DAILY));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
