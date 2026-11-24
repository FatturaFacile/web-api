<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Listeners;

use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\MarketingAutomation\Application\Commands\AttributeSalesOrderToMarketingCampaignCommand;
use Modules\MarketingAutomation\Application\Commands\AttributeSalesOrderToMarketingCampaignHandler;
use Modules\MarketingAutomation\Models\Attribution;
use Modules\MarketingAutomation\Services\AttributionService;
use Throwable;

/**
 * Quotes, orders and invoices feed the ROI of the campaigns. Marketing must never break a sale: failures are reported
 * and swallowed.
 */
final readonly class AttributeSalesDocumentListener
{
    public function __construct(
        private AttributeSalesOrderToMarketingCampaignHandler $handler,
        private AttributionService $attribution,
    ) {}

    public function handleQuoteCreated(SalesQuoteCreatedEvent $event): void
    {
        $this->run($event->tenantId, 'quote', $event->quoteId);
    }

    public function handleOrderCreated(SalesOrderCreatedEvent $event): void
    {
        $this->run($event->tenantId, 'sales_order', $event->salesOrderId);
    }

    public function handleInvoicePosted(InvoicePostedEvent $event): void
    {
        $this->run($event->tenantId, 'invoice', $event->invoiceId);
    }

    public function handleInvoicePaid(InvoicePaidEvent $event): void
    {
        try {
            $existing = Attribution::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->where('document_type', 'invoice')->where('document_id', $event->invoiceId)->first();
            if ($existing !== null) {
                $this->attribution->refreshCollected($existing);
            } else {
                $this->handler->handle(new AttributeSalesOrderToMarketingCampaignCommand($event->tenantId, 'invoice', $event->invoiceId));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function run(string $tenantId, string $type, string $id): void
    {
        try {
            $this->handler->handle(new AttributeSalesOrderToMarketingCampaignCommand($tenantId, $type, $id));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
