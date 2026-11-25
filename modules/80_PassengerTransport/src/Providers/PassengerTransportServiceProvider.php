<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\PassengerTransport\Console\TrackPassengerFlightsCommand;
use Modules\PassengerTransport\Contracts\FdseGatewayInterface;
use Modules\PassengerTransport\Contracts\FlightStatusProviderInterface;
use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Support\FlightScheduleAdjuster;
use Modules\PassengerTransport\Models\DddArchive;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\HotelConciergeContract;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\SchoolBusContract;
use Modules\PassengerTransport\Models\ServiceSheet;
use Modules\PassengerTransport\Policies\PassengerTransportPolicy;
use Modules\PassengerTransport\Services\Flight\AviationStackFlightStatusProvider;
use Modules\PassengerTransport\Services\Flight\SimulatedFlightStatusProvider;
use Modules\PassengerTransport\Services\LocalFdseGateway;
use Modules\PassengerTransport\Services\Routing\GoogleMapsRoutingProvider;
use Modules\PassengerTransport\Services\Routing\HaversineRoutingProvider;
use Modules\PassengerTransport\Services\Routing\OsrmRoutingProvider;

/**
 * Mod 176 — passenger transport vertical: NCC, taxi, GT coach and school bus (Issue #422).
 */
final class PassengerTransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RoutingProviderInterface::class, function (): RoutingProviderInterface {
            $fallback = new HaversineRoutingProvider((float) config('passenger_transport.routing.road_factor'), (float) config('passenger_transport.routing.average_speed_kmh'));

            return match (config('passenger_transport.routing.driver')) {
                'osrm' => new OsrmRoutingProvider((string) config('passenger_transport.routing.osrm_url'), $fallback),
                'google' => new GoogleMapsRoutingProvider(config('passenger_transport.routing.google_key'), $fallback),
                default => $fallback,
            };
        });

        $this->app->bind(FlightStatusProviderInterface::class, fn (): FlightStatusProviderInterface => config('passenger_transport.flights.driver') === 'aviationstack'
            ? new AviationStackFlightStatusProvider(config('passenger_transport.flights.aviationstack_key'))
            : new SimulatedFlightStatusProvider);

        $this->app->bind(FlightScheduleAdjuster::class, fn (): FlightScheduleAdjuster => new FlightScheduleAdjuster((int) config('passenger_transport.flights.buffer_minutes'), (int) config('passenger_transport.flights.threshold_minutes')));
        $this->app->bind(FdseGatewayInterface::class, LocalFdseGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([PassengerBooking::class, FleetVehicle::class, DriverComplianceState::class, DddArchive::class, ServiceSheet::class, HotelConciergeContract::class, SchoolBusContract::class] as $model) {
            Gate::policy($model, PassengerTransportPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([TrackPassengerFlightsCommand::class]);
        }
    }
}
