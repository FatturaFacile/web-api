<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditDispute;
use Modules\CreditManagement\Models\CreditDunningStrategy;
use Modules\CreditManagement\Models\CreditInsuranceCover;
use Modules\CreditManagement\Models\CreditInsurancePolicy;
use Modules\CreditManagement\Models\CreditLimitRequest;
use Modules\CreditManagement\Models\CreditPromiseToPay;
use Modules\CreditManagement\Models\CreditRatingBand;
use Modules\CreditManagement\Models\CreditScore;
use Modules\CreditManagement\Models\CreditScorecardFactor;
use Modules\CreditManagement\Policies\CreditManagementPolicy;
use Modules\CreditManagement\Services\InvoicingReceivablesLookup;

/**
 * Registers the routes, the policy and the receivables lookup of the Credit management module (credit risk and
 * collections).
 */
final class CreditManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReceivablesLookupInterface::class, InvoicingReceivablesLookup::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            CreditScorecardFactor::class,
            CreditRatingBand::class,
            CreditScore::class,
            CreditLimitRequest::class,
            CreditInsurancePolicy::class,
            CreditInsuranceCover::class,
            CreditDunningStrategy::class,
            CreditCollectionCase::class,
            CreditPromiseToPay::class,
            CreditDispute::class,
        ] as $model) {
            Gate::policy($model, CreditManagementPolicy::class);
        }
    }
}
