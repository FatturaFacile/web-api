<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\SupplierPortal\Console\AlertExpiringSupplierDocumentsCommand;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\QualificationCriterion;
use Modules\SupplierPortal\Models\SupplierCheck;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Models\SupplierRiskProfile;
use Modules\SupplierPortal\Policies\SupplierPortalPolicy;

/**
 * Registers the routes, policies and commands of the supplier portal module.
 */
final class SupplierPortalServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([SupplierOnboarding::class, DocumentRequirement::class, SupplierDocumentSubmission::class, QualificationCriterion::class, QualificationAssessment::class, SupplierCheck::class, SupplierRiskProfile::class, BankChangeRequest::class] as $model) {
            Gate::policy($model, SupplierPortalPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AlertExpiringSupplierDocumentsCommand::class]);
        }
    }
}
