<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\GdoTradeTerms\Console\AccrueGdoTradeTermsCommand;
use Modules\GdoTradeTerms\Contracts\GdoLedgerPosterInterface;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Models\GdoSellOutReport;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Policies\GdoTradeTermsPolicy;
use Modules\GdoTradeTerms\Services\GdoLedgerPoster;

/**
 * Mod 231 — GDO framework contracts, tiered year-end rebates (PFA), trade promotions, monthly accruals and settlements (Issue #477).
 */
final class GdoTradeTermsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GdoLedgerPosterInterface::class, GdoLedgerPoster::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([GdoContract::class, GdoScale::class, GdoPromotion::class, GdoDeliveryPoint::class, GdoSellOutReport::class, GdoAccrualRun::class, GdoSettlement::class] as $model) {
            Gate::policy($model, GdoTradeTermsPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AccrueGdoTradeTermsCommand::class]);
        }
    }
}
