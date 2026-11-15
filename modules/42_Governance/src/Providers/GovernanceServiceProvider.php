<?php

declare(strict_types=1);

namespace Modules\Governance\Providers;

use App\Services\Tenancy\SodAssignmentGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Models\GovAuditPlan;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovControlTestRun;
use Modules\Governance\Models\GovFinding;
use Modules\Governance\Models\GovObligation;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Models\GovRiskControl;
use Modules\Governance\Models\GovSodException;
use Modules\Governance\Models\GovSodRule;
use Modules\Governance\Models\GovSodViolation;
use Modules\Governance\Policies\GovernancePolicy;
use Modules\Governance\Services\GovernanceMembers;
use Modules\Governance\Services\SodService;

/**
 * Registers the routes, policies and bindings of the governance, risk and compliance module.
 */
final class GovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GovernanceMembers::class);
        $this->app->bind(SodAssignmentGuard::class, SodService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([GovRisk::class, GovRiskControl::class, GovControl::class, GovControlTestRun::class, GovAuditPlan::class, GovAudit::class, GovFinding::class, GovAction::class, GovObligation::class, GovSodRule::class, GovSodViolation::class, GovSodException::class] as $model) {
            Gate::policy($model, GovernancePolicy::class);
        }
    }
}
