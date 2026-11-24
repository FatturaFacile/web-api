<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ResourcePlanning\Domain\Contracts\CertificatePort;
use Modules\ResourcePlanning\Domain\Contracts\HolidayPort;
use Modules\ResourcePlanning\Domain\Contracts\LeavePort;
use Modules\ResourcePlanning\Domain\Contracts\OpportunityPort;
use Modules\ResourcePlanning\Domain\Contracts\ProjectPort;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Services\CapacityCalculator;
use Modules\ResourcePlanning\Infrastructure\Adapters\CompositeHolidayAdapter;
use Modules\ResourcePlanning\Infrastructure\Adapters\CrmOpportunityAdapter;
use Modules\ResourcePlanning\Infrastructure\Adapters\HrLeaveAdapter;
use Modules\ResourcePlanning\Infrastructure\Adapters\HrResourceDirectoryAdapter;
use Modules\ResourcePlanning\Infrastructure\Adapters\ProjectsAdapter;
use Modules\ResourcePlanning\Infrastructure\Adapters\SafetyCertificateAdapter;

/**
 * Resource planning and capacity saturation (Mod 079): binds the ports of the neighbouring modules (HR contracts and leave,
 * Projects, CRM opportunities, HSE certificates, holiday calendars) to their adapters and loads the routes.
 */
final class ResourcePlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResourceDirectoryPort::class, HrResourceDirectoryAdapter::class);
        $this->app->bind(LeavePort::class, HrLeaveAdapter::class);
        $this->app->bind(HolidayPort::class, CompositeHolidayAdapter::class);
        $this->app->bind(CertificatePort::class, SafetyCertificateAdapter::class);
        $this->app->bind(ProjectPort::class, ProjectsAdapter::class);
        $this->app->bind(OpportunityPort::class, CrmOpportunityAdapter::class);

        // A new calculator for every consumer: it caches the holidays of a location and a year, which depend on the tenant and
        // on the local holidays it records, so the cache must not outlive a request.
        $this->app->bind(CapacityCalculator::class, fn ($app): CapacityCalculator => new CapacityCalculator($app->make(HolidayPort::class)));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
