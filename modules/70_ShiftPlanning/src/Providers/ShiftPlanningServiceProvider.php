<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Providers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Contracts\LaborComplianceStrategyInterface;
use Modules\ShiftPlanning\Domain\Contracts\LeaveManagementPort;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Domain\Contracts\SafetyCertificationPort;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Services\ChLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\Services\ItLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\Services\LaborComplianceStrategyResolver;
use Modules\ShiftPlanning\Domain\Services\PublicHolidayCalendar;
use Modules\ShiftPlanning\Infrastructure\Adapters\EventTimeAndPayrollAdapter;
use Modules\ShiftPlanning\Infrastructure\Adapters\HrEmployeeContractAdapter;
use Modules\ShiftPlanning\Infrastructure\Adapters\HrLeaveManagementAdapter;
use Modules\ShiftPlanning\Infrastructure\Adapters\HrSafetyCertificationAdapter;
use Modules\ShiftPlanning\Infrastructure\Adapters\LaravelNotificationAdapter;

/**
 * Workforce shift planning and roster scheduling (Mod 073): binds the ports of the neighbouring modules to their adapters,
 * tags the labour compliance strategies of each jurisdiction and loads the routes.
 *
 * A collective agreement (CCNL, CCL) plugs in a stricter strategy by tagging a subclass with 'shift_planning.strategies'
 * after this provider; the resolver uses the last strategy of a jurisdiction.
 */
final class ShiftPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PublicHolidayCalendar::class);
        $this->app->singleton(ChLaborComplianceStrategy::class);
        $this->app->singleton(ItLaborComplianceStrategy::class);
        $this->app->tag([ChLaborComplianceStrategy::class, ItLaborComplianceStrategy::class], 'shift_planning.strategies');

        $this->app->singleton(LaborComplianceStrategyResolver::class, fn ($app): LaborComplianceStrategyResolver => new LaborComplianceStrategyResolver($app->tagged('shift_planning.strategies')));

        // The strategy of the establishment of the active tenant, for code that does not choose a jurisdiction itself.
        $this->app->bind(LaborComplianceStrategyInterface::class, fn ($app): LaborComplianceStrategyInterface => $app->make(LaborComplianceStrategyResolver::class)
            ->for(Jurisdiction::fromCountry($app->make(TenantContext::class)->getTenant()?->country)));

        $this->app->bind(LeaveManagementPort::class, HrLeaveManagementAdapter::class);
        $this->app->bind(EmployeeContractPort::class, HrEmployeeContractAdapter::class);
        $this->app->bind(SafetyCertificationPort::class, HrSafetyCertificationAdapter::class);
        $this->app->bind(NotificationPort::class, LaravelNotificationAdapter::class);
        $this->app->bind(TimeAndPayrollIntegrationPort::class, EventTimeAndPayrollAdapter::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
