<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\CRM\Events\ContactCreatedEvent;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\Integrations\Webhooks\WebhookEventCatalog;
use Modules\Inventory\Events\ReorderPointReachedEvent;
use Modules\Inventory\Events\StockBelowMinimumEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;

require_once __DIR__.'/WebhookHelpers.php';

/**
 * One instance of every catalogue event, built the way the services build it.
 *
 * @return array<string, DomainEventInterface>
 */
function webhookCatalogueEventInstances(): array
{
    $id = fn (): string => (string) Str::uuid7();
    $tenant = $id();

    return [
        'invoicing.invoice.posted' => webhookInvoicePostedEvent($tenant),
        'treasury.collection.received' => webhookCollectionReceivedEvent($tenant),
        'sales.order.approved' => new SalesOrderApprovedEvent(salesOrderId: $id(), approvedBy: $id(), tenantId: $tenant, companyId: $tenant),
        'sales.quote.accepted' => new SalesQuoteAcceptedEvent(quoteId: $id(), salesOrderId: null, tenantId: $tenant, companyId: $tenant, quoteNumber: 'Q-1', customerId: $id(), totalAmount: 10.0, salesQuoteId: $id()),
        'treasury.payment.executed' => new PaymentExecutedEvent(paymentBatchId: $id(), bankAccountId: $id(), totalAmount: 5.0, currency: 'EUR', protocol: 'cbi', bankTransmissionReference: 'REF', tenantId: $tenant, companyId: $tenant, executedLineIds: [$id()], valueDate: '2027-01-01'),
        'inventory.reorder_point_reached' => new ReorderPointReachedEvent(reorderRuleId: $id(), stockItemId: $id(), warehouseId: $id(), currentStock: 2.0, reorderPoint: 5.0, suggestedOrderQty: 10.0, preferredSupplierId: null, tenantId: $tenant, companyId: $tenant),
        'esm.ticket.created' => new TicketCreatedEvent(ticketId: $id(), ticketNumber: 'T-1', title: 'Broken', priority: 'high', status: 'open', customerId: null, tenantId: $tenant, companyId: $tenant),
        'crm.lead.created' => new LeadCreatedEvent(leadId: $id(), fullName: 'Mario Rossi', companyName: null, email: null, score: 10, status: 'new', tenantId: $tenant, companyId: $tenant),
        'crm.opportunity.won' => new OpportunityWonEvent(opportunityId: $id(), accountId: $id(), contactId: null, amount: 100.0, currency: 'EUR', salesQuoteId: null, actorUserId: $id(), tenantId: $tenant, companyId: $tenant),
        'invoicing.invoice.paid' => new InvoicePaidEvent(invoiceId: $id(), invoiceNumber: 'FT-1', customerId: null, currency: 'EUR', totalGross: 122.0, totalPaid: 122.0, paidAt: now()->toIso8601String(), tenantId: $tenant, companyId: $tenant),
        'crm.contact.created' => new ContactCreatedEvent(contactId: $id(), contactType: 'customer', companyName: 'ACME', firstName: null, lastName: null, email: null, country: 'IT', vatNumber: null, tenantId: $tenant, companyId: $tenant),
        'inventory.stock_below_minimum' => new StockBelowMinimumEvent(reorderRuleId: $id(), stockItemId: $id(), warehouseId: $id(), availableStock: 1.0, minimumStock: 5.0, tenantId: $tenant, companyId: $tenant),
        'procurement.purchase_order.approved' => new PurchaseOrderApprovedEvent(purchaseOrderId: $id(), poNumber: 'PO-1', approvedByUserId: $id(), totalAmount: 50.0, currency: 'EUR', tenantId: $tenant, companyId: $tenant, supplierId: $id()),
    ];
}

test('every catalogue entry is an event class the codebase defines, with the name the class reports', function (): void {
    $instances = webhookCatalogueEventInstances();

    expect(array_keys($instances))->toEqualCanonicalizing(WebhookEventCatalog::names());

    foreach (WebhookEventCatalog::all() as $name => $definition) {
        expect($instances[$name])->toBeInstanceOf($definition['event_class'])->and($instances[$name]->getEventName())->toBe($name);
    }
});

test('the documented data fields match the payload of every event', function (): void {
    foreach (webhookCatalogueEventInstances() as $name => $event) {
        $documented = array_keys(WebhookEventCatalog::get($name)['fields']);
        $actual = array_keys(WebhookEventCatalog::dataFrom($event->toPayload()));

        expect($actual)->toEqualCanonicalizing($documented, "Payload of {$name} differs from its documented fields");
    }
});

test('the envelope keys are not repeated in the data', function (): void {
    foreach (webhookCatalogueEventInstances() as $event) {
        expect(WebhookEventCatalog::dataFrom($event->toPayload()))->not->toHaveKeys(['tenant_id', 'company_id', 'event_id', 'occurred_at', 'tenantId', 'companyId']);
    }
});

test('each event is hooked on the channel it really travels on', function (): void {
    foreach (WebhookEventCatalog::all() as $name => $definition) {
        $hooked = $definition['channel'] === WebhookEventCatalog::ChannelClass ? $definition['event_class'] : $name;

        expect(Event::hasListeners($hooked))->toBeTrue("No webhook listener on {$hooked}");
    }
});

test('the invoice posted event is raised by publishAndDispatch and the collection received event is only published', function (): void {
    $invoicing = file_get_contents(base_path('modules/05_Invoicing/src/Services/InvoicingService.php'));
    $treasury = file_get_contents(base_path('modules/24_Treasury/src/Services/ReceivableCollectionService.php'));

    expect($invoicing)->toContain('new InvoicePostedEvent(')->toContain('publishAndDispatch($event)')
        ->and($treasury)->toContain('$this->dispatcher->publish($event)')
        ->and(WebhookEventCatalog::get('invoicing.invoice.posted')['channel'])->toBe(WebhookEventCatalog::ChannelClass)
        ->and(WebhookEventCatalog::get('treasury.collection.received')['channel'])->toBe(WebhookEventCatalog::ChannelOutbox);
});
