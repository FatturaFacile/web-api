<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use Illuminate\Support\Str;
use Modules\CRM\Events\ContactCreatedEvent;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\Integrations\Webhooks\Exceptions\UnknownWebhookEvent;
use Modules\Inventory\Events\ReorderPointReachedEvent;
use Modules\Inventory\Events\StockBelowMinimumEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;

/**
 * The domain events a customer can subscribe to. Every entry is an event the codebase already raises (the class named
 * in "event_class" is built by the service named in its docblock); nothing is invented for webhooks.
 *
 * "channel" says where the subscriber hooks in, so that an event reaches an endpoint exactly once:
 * - class: the event object is dispatched in-process (EventDispatcher::dispatch or publishAndDispatch), so a listener
 *   on the class receives it at once, inside the transaction of the caller;
 * - outbox: the event is only published to the transactional outbox (EventDispatcher::publish), and the outbox relay
 *   dispatches it later by its name with the array payload.
 *
 * "fields" describes the "data" member of the webhook payload: the event payload with snake_case keys, without the
 * keys the envelope carries (tenant, company, event id, occurrence time). A test keeps the fields in line with the
 * payload of each event class.
 *
 * Three events were added to the business code for webhooks, each raised by a model observer or a service at the one
 * place where the state changes: invoicing.invoice.paid (InvoicePaidObserver, on the transition to "paid"),
 * crm.contact.created (ContactCreatedObserver, on insert, whichever path creates the contact) and
 * inventory.stock_below_minimum (InventoryReplenishmentService::evaluateMinimumStock, on the crossing of the minimum
 * stock level of a reorder rule).
 */
final class WebhookEventCatalog
{
    public const string ChannelClass = 'class';

    public const string ChannelOutbox = 'outbox';

    /**
     * Keys of an event payload that the envelope already carries.
     *
     * @var list<string>
     */
    private const array EnvelopeKeys = ['tenant_id', 'company_id', 'event_id', 'occurred_at'];

    /**
     * @return array<string, array{label: string, module: string, description: string, channel: string, event_class: class-string, fields: array<string, array{type: string, nullable: bool, description: string}>}>
     */
    public static function all(): array
    {
        $str = static fn (string $description, bool $nullable = false): array => ['type' => 'string', 'nullable' => $nullable, 'description' => $description];
        $num = static fn (string $description, bool $nullable = false): array => ['type' => 'number', 'nullable' => $nullable, 'description' => $description];

        return [
            'invoicing.invoice.posted' => [
                'label' => 'Invoice issued',
                'module' => 'invoicing',
                'description' => 'A sales invoice has been posted (issued) and is final.',
                'channel' => self::ChannelClass,
                'event_class' => InvoicePostedEvent::class,
                'fields' => [
                    'invoice_id' => $str('Invoice id (UUID)'),
                    'invoice_number' => $str('Invoice number'),
                    'customer_id' => $str('Customer id (UUID)'),
                    'date' => $str('Invoice date, Y-m-d'),
                    'due_date' => $str('Due date, Y-m-d', true),
                    'currency' => $str('ISO 4217 currency code'),
                    'exchange_rate' => $num('Rate to the functional currency'),
                    'total_net' => $num('Total before tax'),
                    'total_tax' => $num('Total tax'),
                    'total_gross' => $num('Total including tax'),
                    'revenue_lines' => ['type' => 'array', 'nullable' => false, 'description' => 'Revenue lines posted to the ledger'],
                    'tax_lines' => ['type' => 'array', 'nullable' => false, 'description' => 'Tax lines posted to the ledger'],
                    'gl_journal_entry_id' => $str('Ledger entry id, when the posting already exists', true),
                ],
            ],
            'sales.order.approved' => [
                'label' => 'Order confirmed',
                'module' => 'sales',
                'description' => 'A sales order has been approved (confirmed).',
                'channel' => self::ChannelClass,
                'event_class' => SalesOrderApprovedEvent::class,
                'fields' => [
                    'sales_order_id' => $str('Sales order id (UUID)'),
                    'approved_by' => $str('Id of the user who approved it'),
                ],
            ],
            'sales.quote.accepted' => [
                'label' => 'Quote accepted',
                'module' => 'sales',
                'description' => 'A customer accepted a sales quote.',
                'channel' => self::ChannelClass,
                'event_class' => SalesQuoteAcceptedEvent::class,
                'fields' => [
                    'quote_id' => $str('Quote id (UUID)'),
                    'sales_order_id' => $str('Order created from the quote, if any', true),
                    'quote_number' => $str('Quote number', true),
                    'customer_id' => $str('Customer id (UUID)', true),
                    'total_amount' => $num('Quote total'),
                    'sales_quote_id' => $str('Sales quote id (UUID)', true),
                ],
            ],
            'treasury.collection.received' => [
                'label' => 'Payment received',
                'module' => 'treasury',
                'description' => 'A collection from a customer has been registered. When it settles an invoice, invoice_id names it: this is the signal that an invoice has been paid.',
                'channel' => self::ChannelOutbox,
                'event_class' => CollectionReceivedEvent::class,
                'fields' => [
                    'collection_id' => $str('Collection id (UUID)'),
                    'customer_id' => $str('Customer id (UUID)'),
                    'invoice_id' => $str('Invoice the collection settles, if any', true),
                    'collected_amount' => $num('Amount collected'),
                    'collection_date' => $str('Collection date'),
                ],
            ],
            'treasury.payment.executed' => [
                'label' => 'Supplier payment executed',
                'module' => 'treasury',
                'description' => 'A batch of supplier payments has been sent to the bank.',
                'channel' => self::ChannelOutbox,
                'event_class' => PaymentExecutedEvent::class,
                'fields' => [
                    'payment_batch_id' => $str('Payment batch id (UUID)'),
                    'bank_account_id' => $str('Bank account id (UUID)'),
                    'total_amount' => $num('Total of the batch'),
                    'currency' => $str('ISO 4217 currency code'),
                    'protocol' => $str('Transmission protocol'),
                    'bank_transmission_reference' => $str('Reference given by the bank channel'),
                    'executed_line_ids' => ['type' => 'array', 'nullable' => false, 'description' => 'Ids of the executed payment lines'],
                    'value_date' => $str('Value date', true),
                ],
            ],
            'inventory.reorder_point_reached' => [
                'label' => 'Stock below threshold',
                'module' => 'inventory',
                'description' => 'The stock of an item in a warehouse fell to its reorder point.',
                'channel' => self::ChannelClass,
                'event_class' => ReorderPointReachedEvent::class,
                'fields' => [
                    'reorder_rule_id' => $str('Reorder rule id (UUID)'),
                    'stock_item_id' => $str('Stock item id (UUID)'),
                    'warehouse_id' => $str('Warehouse id (UUID)'),
                    'current_stock' => $num('Stock on hand'),
                    'reorder_point' => $num('Threshold of the rule'),
                    'suggested_order_qty' => $num('Quantity suggested for the order'),
                    'preferred_supplier_id' => $str('Preferred supplier id (UUID)', true),
                ],
            ],
            'esm.ticket.created' => [
                'label' => 'Ticket opened',
                'module' => 'service_management',
                'description' => 'A service ticket has been opened.',
                'channel' => self::ChannelClass,
                'event_class' => TicketCreatedEvent::class,
                'fields' => [
                    'ticket_id' => $str('Ticket id (UUID)'),
                    'ticket_number' => $str('Ticket number'),
                    'title' => $str('Title'),
                    'priority' => $str('Priority'),
                    'status' => $str('Status'),
                    'customer_id' => $str('Customer id (UUID)', true),
                ],
            ],
            'crm.lead.created' => [
                'label' => 'Lead created',
                'module' => 'crm',
                'description' => 'A lead has been created in the CRM. It carries personal data of the lead: subscribe only if the receiver is entitled to it.',
                'channel' => self::ChannelOutbox,
                'event_class' => LeadCreatedEvent::class,
                'fields' => [
                    'lead_id' => $str('Lead id (UUID)'),
                    'full_name' => $str('Full name'),
                    'company_name' => $str('Company name', true),
                    'email' => $str('Email address', true),
                    'score' => ['type' => 'integer', 'nullable' => false, 'description' => 'Lead score'],
                    'status' => $str('Lead status'),
                ],
            ],
            'crm.opportunity.won' => [
                'label' => 'Opportunity won',
                'module' => 'crm',
                'description' => 'A CRM opportunity has been marked as won.',
                'channel' => self::ChannelOutbox,
                'event_class' => OpportunityWonEvent::class,
                'fields' => [
                    'opportunity_id' => $str('Opportunity id (UUID)'),
                    'account_id' => $str('Account id (UUID)'),
                    'contact_id' => $str('Contact id (UUID)', true),
                    'amount' => $num('Opportunity amount'),
                    'currency' => $str('ISO 4217 currency code'),
                    'sales_quote_id' => $str('Linked quote id (UUID)', true),
                    'actor_user_id' => $str('Id of the user who won it'),
                ],
            ],
            'invoicing.invoice.paid' => [
                'label' => 'Invoice paid',
                'module' => 'invoicing',
                'description' => 'A sales invoice has become fully settled (payments and applied credit notes cover what is due). Raised once per transition to paid.',
                'channel' => self::ChannelClass,
                'event_class' => InvoicePaidEvent::class,
                'fields' => [
                    'invoice_id' => $str('Invoice id (UUID)'),
                    'invoice_number' => $str('Invoice number'),
                    'customer_id' => $str('Customer id (UUID)', true),
                    'currency' => $str('ISO 4217 currency code'),
                    'total_gross' => $num('Total including tax'),
                    'total_paid' => $num('Amount settled by payments'),
                    'paid_at' => $str('When the invoice became paid, ISO 8601'),
                ],
            ],
            'crm.contact.created' => [
                'label' => 'Contact created',
                'module' => 'crm',
                'description' => 'A contact (customer, supplier or person) has been created, by any path: API, import, lead conversion. It carries personal data: subscribe only if the receiver is entitled to it.',
                'channel' => self::ChannelClass,
                'event_class' => ContactCreatedEvent::class,
                'fields' => [
                    'contact_id' => $str('Contact id (UUID)'),
                    'contact_type' => $str('customer, supplier or another contact type'),
                    'company_name' => $str('Company name', true),
                    'first_name' => $str('First name', true),
                    'last_name' => $str('Last name', true),
                    'email' => $str('Email address', true),
                    'country' => $str('ISO 3166-1 alpha-2 country', true),
                    'vat_number' => $str('VAT number', true),
                ],
            ],
            'inventory.stock_below_minimum' => [
                'label' => 'Stock below minimum',
                'module' => 'inventory',
                'description' => 'Stock dropped below a configured minimum. Raised on the crossing only, not again while the stock stays below; distinct from the reorder point event. Two scopes with independent minimums, told apart by scope: warehouse (available stock of an item in one warehouse below min_stock_level of its reorder rule) and product (stock of a catalog product across all warehouses below the product min_stock, the figure of the Low stock widget). When both minimums exist both can fire for one movement: filter on scope to get one signal.',
                'channel' => self::ChannelClass,
                'event_class' => StockBelowMinimumEvent::class,
                'fields' => [
                    'scope' => $str('warehouse or product'),
                    'reorder_rule_id' => $str('Reorder rule id (UUID); scope warehouse only', true),
                    'stock_item_id' => $str('Stock item id (UUID); scope warehouse only', true),
                    'warehouse_id' => $str('Warehouse id (UUID); scope warehouse only', true),
                    'product_id' => $str('Catalog product id (UUID): always for scope product, for scope warehouse when the item belongs to a product', true),
                    'available_stock' => $num('Scope warehouse: on hand minus reserved, outside quarantine and transit. Scope product: stock across warehouses net of what was sold beyond it'),
                    'minimum_stock' => $num('The minimum that was crossed: min_stock_level of the rule or min_stock of the product'),
                ],
            ],
            'procurement.purchase_order.approved' => [
                'label' => 'Purchase order approved',
                'module' => 'procurement',
                'description' => 'A purchase order has been approved.',
                'channel' => self::ChannelClass,
                'event_class' => PurchaseOrderApprovedEvent::class,
                'fields' => [
                    'purchase_order_id' => $str('Purchase order id (UUID)'),
                    'po_number' => $str('Purchase order number'),
                    'supplier_id' => $str('Supplier id (UUID)'),
                    'total_amount' => $num('Order total'),
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $eventName): bool
    {
        return array_key_exists($eventName, self::all());
    }

    /**
     * @return array{label: string, module: string, description: string, channel: string, event_class: class-string, fields: array<string, array{type: string, nullable: bool, description: string}>}
     *
     * @throws UnknownWebhookEvent
     */
    public static function get(string $eventName): array
    {
        return self::all()[$eventName] ?? throw new UnknownWebhookEvent($eventName);
    }

    /**
     * The "data" member of a payload: the keys of the event payload in snake_case, without the ones of the envelope.
     *
     * @param  array<string, mixed>  $eventPayload
     * @return array<string, mixed>
     */
    public static function dataFrom(array $eventPayload): array
    {
        $data = [];

        foreach ($eventPayload as $key => $value) {
            $snake = Str::snake((string) $key);

            if (! in_array($snake, self::EnvelopeKeys, true)) {
                $data[$snake] = $value;
            }
        }

        return $data;
    }
}
