<?php

declare(strict_types=1);

namespace Modules\Customs\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Customs\Console\ImportCustomsMasterDataCommand;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsConsolidation;
use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsLedgerPosting;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsQuote;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\Customs\Models\CustomsServiceDiscount;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Models\CustomsShipmentRequest;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffGrid;
use Modules\Customs\Models\CustomsTariffNumber;
use Modules\Customs\Policies\CustomsPolicy;
use Modules\Customs\Services\CustomsGatewayManager;

final class CustomsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/customs.php', 'customs');
        $this->app->singleton(CustomsGatewayManager::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'customs');

        foreach ([
            CustomsTariffNumber::class, CustomsStatisticalKey::class, CustomsPackagingType::class, CustomsCountry::class,
            CustomsDocumentType::class, CustomsSettings::class, CustomsDeclaration::class, CustomsAssessmentDecision::class,
            CustomsRetrievalRun::class, CustomsLedgerPosting::class, CustomsTariffGrid::class, CustomsServiceDiscount::class,
            CustomsConsolidation::class, CustomsDossier::class, CustomsQuote::class, CustomsShipmentRequest::class,
        ] as $model) {
            Gate::policy($model, CustomsPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ImportCustomsMasterDataCommand::class]);
        }
    }
}
