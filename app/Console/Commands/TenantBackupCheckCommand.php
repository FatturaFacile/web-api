<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Backup\BackupStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Alerts when the backup of a tenant is stale (older than `backup.rpo_hours`) or failing. One critical entry per
 * affected tenant is written to the `backup.alert_channel` log channel (the default channel when unset, e.g. a Slack
 * channel in production). Tenants that never had a backup are not alerted: they are new or not backed up yet.
 */
class TenantBackupCheckCommand extends Command
{
    protected $signature = 'tenants:backup-check';

    protected $description = 'Alert when a tenant backup is stale or failing against the RPO';

    public function handle(BackupStatusService $status): int
    {
        if (config('app.demo_reset')) {
            return self::SUCCESS;
        }

        $alerts = 0;

        foreach (Tenant::query()->get() as $tenant) {
            $report = $status->forTenant($tenant->id);

            if (! in_array($report['state'], ['stale', 'failing'], true)) {
                continue;
            }

            $alerts++;
            $this->warn("Tenant {$tenant->id}: backup {$report['state']}.");
            Log::channel(config('backup.alert_channel') ?: null)->critical('Backup of tenant {tenant} is {state} (RPO {rpo}h, last completed {last}, error {error})', [
                'tenant' => $tenant->id,
                'state' => $report['state'],
                'rpo' => $report['rpo_hours'],
                'last' => $report['last_completed_at'] ?? 'never',
                'error' => $report['last_error'] ?? 'none',
            ]);
        }

        $this->info("{$alerts} backup alert(s).");

        return $alerts === 0 ? self::SUCCESS : self::FAILURE;
    }
}
