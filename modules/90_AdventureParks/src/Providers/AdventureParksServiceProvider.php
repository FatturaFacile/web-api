<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AdventureParks\Contracts\SchoolInvoicePort;
use Modules\AdventureParks\Infrastructure\InvoicingSchoolInvoiceAdapter;
use Modules\AdventureParks\Models\DailyCourseCheck;
use Modules\AdventureParks\Models\EducationalWorkshop;
use Modules\AdventureParks\Models\ParkConfig;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\PpeInspectionLog;
use Modules\AdventureParks\Models\SafetyWaiver;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Policies\AdventureParksPolicy;

/**
 * Mod 299 — Adventure parks, climbing centres and educational farms (Issue #545).
 */
final class AdventureParksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SchoolInvoicePort::class, InvoicingSchoolInvoiceAdapter::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([ParkConfig::class, SlotTemplate::class, PpeAsset::class, PpeInspectionLog::class, DailyCourseCheck::class, Ticket::class, SafetyWaiver::class, EducationalWorkshop::class, SchoolBooking::class] as $model) {
            Gate::policy($model, AdventureParksPolicy::class);
        }
    }
}
