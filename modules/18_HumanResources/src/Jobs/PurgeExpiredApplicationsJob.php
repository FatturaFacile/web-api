<?php

declare(strict_types=1);

namespace Modules\HumanResources\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\HumanResources\Models\JobApplication;

/**
 * Erases the applications whose retention ended, with their stage history and interview notes. An application without a
 * date is still running. The log holds a count and no name.
 */
final class PurgeExpiredApplicationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $deleted = JobApplication::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', CarbonImmutable::now('Europe/Zurich')->format('Y-m-d'))
            ->delete();

        if ($deleted > 0) {
            Log::info('Applications erased at the end of their retention.', ['tenant_id' => $this->tenantId, 'count' => $deleted]);
        }
    }
}
