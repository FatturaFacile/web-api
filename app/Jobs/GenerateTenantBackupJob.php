<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TenantBackup;
use App\Services\Backup\TenantBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the archive of a pending tenant backup in the background. The outcome (completed or failed) is stored on the
 * backup record and, once completed, the retention policy removes the archives beyond the limit.
 */
class GenerateTenantBackupJob implements ShouldQueue
{
    use Queueable;

    /**
     * The service records failures itself, so retrying the whole job is pointless.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $backupId) {}

    public function handle(TenantBackupService $service): void
    {
        $backup = TenantBackup::withoutGlobalScopes()->find($this->backupId);

        if ($backup === null || $backup->status !== TenantBackup::PENDING) {
            return;
        }

        $service->generate($backup);
    }
}
