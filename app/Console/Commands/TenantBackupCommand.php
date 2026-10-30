<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateTenantBackupJob;
use App\Models\Tenant;
use App\Services\Backup\TenantBackupService;
use Illuminate\Console\Command;

/**
 * Queues the backup of one tenant (`--tenant=<uuid>`) or of every active tenant (`--all`). Each backup is built by
 * `GenerateTenantBackupJob`, which also applies the retention policy (the 7 most recent completed backups are kept).
 */
class TenantBackupCommand extends Command
{
    protected $signature = 'tenants:backup {--tenant= : Specific tenant UUID} {--all : Backup all active tenants}';

    protected $description = 'Queue a backup of one tenant or of all active tenants';

    public function handle(TenantBackupService $backups): int
    {
        $tenantId = $this->option('tenant');

        if ((bool) $this->option('all') === ($tenantId !== null)) {
            $this->error('Specify exactly one of --tenant or --all.');

            return self::INVALID;
        }

        $tenants = $tenantId !== null
            ? Tenant::query()->whereKey($tenantId)->get()
            : Tenant::query()->get();

        if ($tenantId !== null && $tenants->isEmpty()) {
            $this->error("Tenant {$tenantId} was not found.");

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            GenerateTenantBackupJob::dispatch($backups->create($tenant)->id);
        }

        $this->info("Backup queued for {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
