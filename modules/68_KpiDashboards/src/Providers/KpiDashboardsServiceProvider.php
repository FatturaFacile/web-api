<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Providers;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Modules\CRM\Models\Opportunity;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\HumanResources\Models\Timesheet;
use Modules\KpiDashboards\Infrastructure\Services\KpiCache;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * Executive and role-based KPI dashboards (Mod 090): routes and the invalidation of the pre-aggregated KPI payloads when
 * a document behind a KPI changes (invoice, journal entry, sales order, stock movement, opportunity, production order,
 * timesheet). The version bump is per tenant, so one posting never recomputes the cockpits of another company.
 */
final class KpiDashboardsServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Model>> */
    private const array SourceModels = [
        Invoice::class, PassiveInvoice::class, JournalEntry::class, SalesOrder::class, StockMovement::class,
        Opportunity::class, MfgProductionOrder::class, Timesheet::class,
    ];

    public function register(): void
    {
        $this->app->singleton(KpiCache::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach (self::SourceModels as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::{$event}(static function (Model $document): void {
                    $tenantId = $document->getAttribute('tenant_id');

                    if (is_string($tenantId) && $tenantId !== '') {
                        app(KpiCache::class)->invalidate($tenantId);
                    }
                });
            }
        }
    }
}
