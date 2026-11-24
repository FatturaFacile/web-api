<?php

declare(strict_types=1);

namespace Modules\Transportation\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsFreightInvoice;
use Modules\Transportation\Models\TmsFreightInvoiceLine;
use Modules\Transportation\Models\TmsQuote;
use Modules\Transportation\Models\TmsTariffBand;
use Modules\Transportation\Models\TmsTariffTable;
use Modules\Transportation\Models\TmsTrackingEvent;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;
use Modules\Transportation\Models\TmsTripStop;
use Modules\Transportation\Policies\TransportationPolicy;

/**
 * Registers the routes and the policy of the Transportation management (TMS) module.
 */
final class TransportationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            TmsCarrier::class,
            TmsTariffTable::class,
            TmsTariffBand::class,
            TmsTrip::class,
            TmsTransportOrder::class,
            TmsTripStop::class,
            TmsQuote::class,
            TmsTrackingEvent::class,
            TmsFreightInvoice::class,
            TmsFreightInvoiceLine::class,
        ] as $model) {
            Gate::policy($model, TransportationPolicy::class);
        }
    }
}
