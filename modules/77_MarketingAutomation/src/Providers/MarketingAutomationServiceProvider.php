<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\MarketingAutomation\Listeners\AttributeSalesDocumentListener;
use Modules\MarketingAutomation\Services\MarketingCopy;

/**
 * Mod 155 — Marketing Automation, Newsletter DEM, RFM segmentation and omnichannel campaigns with ROI tracking.
 */
final class MarketingAutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketingCopy::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen(SalesQuoteCreatedEvent::class, [AttributeSalesDocumentListener::class, 'handleQuoteCreated']);
        Event::listen(SalesOrderCreatedEvent::class, [AttributeSalesDocumentListener::class, 'handleOrderCreated']);
        Event::listen(InvoicePostedEvent::class, [AttributeSalesDocumentListener::class, 'handleInvoicePosted']);
        Event::listen(InvoicePaidEvent::class, [AttributeSalesDocumentListener::class, 'handleInvoicePaid']);
    }
}
