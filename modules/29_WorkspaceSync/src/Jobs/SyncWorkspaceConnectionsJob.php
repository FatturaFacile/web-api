<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Services\WorkspaceSyncService;
use Throwable;

/**
 * Syncs the connections of a tenant. One failing mailbox does not stop the others.
 */
final class SyncWorkspaceConnectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Inject the collaborators.
     */
    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the unit of work.
     */
    public function handle(WorkspaceSyncService $sync): void
    {
        $connections = WorkspaceConnection::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->whereNull('revoked_at')
            ->get();

        foreach ($connections as $connection) {
            try {
                $sync->sync($connection);
            } catch (Throwable $exception) {
                Log::warning("Workspace sync failed for connection {$connection->id}: {$exception->getMessage()}");
            }
        }
    }
}
