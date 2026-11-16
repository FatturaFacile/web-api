<?php

declare(strict_types=1);

namespace Modules\Facility\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Facility\Services\BookingService;

/**
 * Releases the bookings nobody checked in to within the grace period of their kind of space, and completes the bookings
 * that ended.
 */
final class ReleaseNoShowBookingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->is_maintenance) {
            return;
        }

        $result = $tenantContext->runWithTenant($tenant, static fn (): array => app(BookingService::class)->releaseNoShows());

        if ($result['released'] > 0) {
            Log::info('Facility bookings released as no-show.', ['tenant_id' => $this->tenantId, ...$result]);
        }
    }
}
