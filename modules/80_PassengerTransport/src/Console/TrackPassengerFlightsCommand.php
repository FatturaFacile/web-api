<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\FlightTrackingService;

/**
 * Polls the flight status provider for the airport transfers about to start and moves their pickup. Safe to repeat.
 */
final class TrackPassengerFlightsCommand extends Command
{
    protected $signature = 'passenger-transport:track-flights {--tenant=* : Limit to these tenants}';

    protected $description = 'Follows the flights of the airport transfers and adjusts the pickup to the real arrival';

    public function handle(TenantContext $tenantContext, FlightTrackingService $tracking): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $tracking): void {
                if (! PassengerBooking::query()->whereNotNull('flight_or_train_number')->exists()) {
                    return;
                }

                $this->line("{$tenant->id}: ".$tracking->trackDue().' pickup(s) adjusted');
            });
        }

        return self::SUCCESS;
    }
}
