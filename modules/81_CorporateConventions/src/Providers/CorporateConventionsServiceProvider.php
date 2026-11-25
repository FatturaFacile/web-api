<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CorporateConventions\Console\ExpireConventionsCommand;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;
use Modules\CorporateConventions\Policies\ConventionPolicy;

/**
 * Mod 358 — corporate conventions, partner employee discounts and B2B welfare cards (Issue #604).
 */
final class CorporateConventionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([CorporatePartnerConvention::class, ConventionBeneficiaryEnrollment::class, ConventionSplitBilling::class, WelfareVoucherRedemption::class] as $model) {
            Gate::policy($model, ConventionPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireConventionsCommand::class]);
        }
    }
}
