<?php

declare(strict_types=1);

namespace Modules\GreenCare\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Infrastructure\Weather\OpenMeteoForecastProvider;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\CrewTeamMember;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;
use Modules\GreenCare\Policies\GreenCarePolicy;

/**
 * Mod 126 — Gardening and green maintenance vertical: sites, seasonal contracts (condominiums), crews and operator licenses, weekly routes with
 * weather, phytosanitary diary (Quaderno di Campagna) and green waste slips (Issue #372).
 */
final class GreenCareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IWeatherForecastProvider::class, OpenMeteoForecastProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([
            GreenSpaceSite::class, SeasonalMaintenanceContract::class, CrewTeam::class, CrewTeamMember::class, OperatorPhytosanitaryLicense::class,
            CrewRouteSchedule::class, CrewRouteStop::class, PhytosanitaryTreatmentLog::class, GreenWasteSlip::class,
        ] as $model) {
            Gate::policy($model, GreenCarePolicy::class);
        }
    }
}
