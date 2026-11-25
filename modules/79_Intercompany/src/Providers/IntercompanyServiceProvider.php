<?php

declare(strict_types=1);

namespace Modules\Intercompany\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\GeneralLedger\Contracts\ConsolidationRateOverrides;
use Modules\Intercompany\Listeners\IntercompanyDocumentListener;
use Modules\Intercompany\Policies\IntercompanyPolicy;
use Modules\Intercompany\Services\PeriodRateService;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;

/**
 * Intercompany accounting and the consolidation workflow (Mod 021): routes, the two abilities, the listeners that mirror
 * the documents issued between the entities of a group and carry their payments over, and the period rates that the
 * consolidation translator reads before the published exchange rates.
 */
final class IntercompanyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConsolidationRateOverrides::class, PeriodRateService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::define('intercompany.view', [IntercompanyPolicy::class, 'view']);
        Gate::define('intercompany.manage', [IntercompanyPolicy::class, 'manage']);

        Event::listen(InvoicePostedEvent::class, [IntercompanyDocumentListener::class, 'handlePosted']);
        Event::listen(CreditNoteIssuedEvent::class, [IntercompanyDocumentListener::class, 'handleCreditNote']);
        Event::listen(InvoicePaidEvent::class, [IntercompanyDocumentListener::class, 'handlePaid']);
    }
}
