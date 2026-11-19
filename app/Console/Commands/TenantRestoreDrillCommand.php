<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Backup\RestoreDrillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Weekly disaster recovery drill: verifies the latest backup of a sample tenant restores (`--tenant=<uuid>`, otherwise
 * `--sample` tenants picked at random among those that have a completed backup). Never touches live data. A failed
 * drill is logged as critical on the backup alert channel and the command exits with failure.
 */
class TenantRestoreDrillCommand extends Command
{
    protected $signature = 'tenants:restore-drill {--tenant= : Specific tenant UUID} {--sample=1 : Number of random tenants to drill}';

    protected $description = 'Verify that the latest backup of a sample tenant can be restored, without touching live data';

    public function handle(RestoreDrillService $drill): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId !== null) {
            $tenants = Tenant::query()->whereKey($tenantId)->get();

            if ($tenants->isEmpty()) {
                $this->error("Tenant {$tenantId} was not found.");

                return self::FAILURE;
            }
        } else {
            $withBackup = TenantBackup::withoutGlobalScopes()->where('status', TenantBackup::COMPLETED)->select('tenant_id');
            $tenants = Tenant::query()->whereIn('id', $withBackup)->inRandomOrder()->limit(max(1, (int) $this->option('sample')))->get();

            if ($tenants->isEmpty()) {
                $this->info('No tenant has a completed backup: nothing to drill.');

                return self::SUCCESS;
            }
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $drill->run($tenant);

            if ($result['ok']) {
                $this->info("Tenant {$tenant->id}: backup {$result['backup_id']} verified ({$result['tables']} tables, {$result['rows']} rows, {$result['files']} files, {$result['seconds']}s).");

                continue;
            }

            $failed++;
            $this->error("Tenant {$tenant->id}: restore drill FAILED: {$result['error']}");
            Log::channel(config('backup.alert_channel') ?: null)->critical('Restore drill of tenant {tenant} failed: {error}', ['tenant' => $tenant->id, 'error' => $result['error'], 'backup' => $result['backup_id']]);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
