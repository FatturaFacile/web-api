<?php

declare(strict_types=1);

namespace Modules\Associations\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\Services\MembershipService;

/**
 * Daily housekeeping: memberships past their end, and import previews never confirmed, whose rows hold personal data.
 */
final class ExpireMembershipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const int ImportPreviewDays = 7;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, MembershipService $memberships): void
    {
        $tenantContext->runWithTenant(Tenant::query()->findOrFail($this->tenantId), static function () use ($memberships): void {
            $memberships->expirePast();

            ImportBatch::query()->where('status', ImportBatch::Preview)
                ->where('created_at', '<', now()->subDays(self::ImportPreviewDays))
                ->get()
                ->each(static fn (ImportBatch $batch) => $batch->forceDelete());
        });
    }
}
