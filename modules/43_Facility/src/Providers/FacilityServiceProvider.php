<?php

declare(strict_types=1);

namespace Modules\Facility\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilityBookingRule;
use Modules\Facility\Models\FacilityBookingSeries;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Models\FacilityInspectionTemplate;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityMeterReading;
use Modules\Facility\Models\FacilityRequestComment;
use Modules\Facility\Models\FacilityServiceRequest;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Policies\FacilityPolicy;

/**
 * Registers the routes and policies of the facility management module.
 */
final class FacilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            FacilityBuilding::class, FacilitySpace::class, FacilityBooking::class, FacilityBookingSeries::class, FacilityBookingRule::class,
            FacilityServiceRequest::class, FacilityRequestComment::class, FacilityInspectionTemplate::class, FacilityInspection::class,
            FacilityMeter::class, FacilityMeterReading::class,
        ] as $model) {
            Gate::policy($model, FacilityPolicy::class);
        }
    }
}
