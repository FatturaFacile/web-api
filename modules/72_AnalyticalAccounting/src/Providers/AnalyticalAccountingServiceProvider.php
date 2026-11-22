<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AnalyticalAccounting\Listeners\AnalyticalInvoiceListener;
use Modules\AnalyticalAccounting\Listeners\AnalyticalJournalListener;
use Modules\AnalyticalAccounting\Models\AllocationDriver;
use Modules\AnalyticalAccounting\Models\AnalyticalCenter;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Models\ApportionmentRun;
use Modules\AnalyticalAccounting\Models\ApportionmentSchedule;
use Modules\AnalyticalAccounting\Models\DerivationRule;
use Modules\AnalyticalAccounting\Policies\AnalyticalAccountingPolicy;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;

/**
 * Registers the routes, the policy and the ledger hooks of the industrial and analytical cost accounting module (Mod 018).
 * The hooks only read the documents of the other modules and write the separate management ledger.
 */
final class AnalyticalAccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([AnalyticalCenter::class, AllocationDriver::class, DerivationRule::class, AnalyticalEntry::class, ApportionmentSchedule::class, ApportionmentRun::class] as $model) {
            Gate::policy($model, AnalyticalAccountingPolicy::class);
        }

        Event::listen(InvoicePostedEvent::class, [AnalyticalInvoiceListener::class, 'handle']);
        Event::listen(JournalPostedEvent::class, [AnalyticalJournalListener::class, 'handle']);
    }
}
