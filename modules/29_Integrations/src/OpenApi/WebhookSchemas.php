<?php

declare(strict_types=1);

namespace Modules\Integrations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * OpenAPI schemas of the outbound webhooks: the request and response bodies of the management endpoints, the payload
 * envelope a receiver gets, and the "data" of every event of the catalogue (WebhookEventCatalog). The data schemas
 * are generated from the catalogue; a test keeps the catalogue in line with the payload of the event classes.
 *
 * The verification recipe for receivers is in WebhookDocumentation::SignatureRecipe, repeated in the description of
 * the endpoints that hand out a secret.
 */
#[OA\Schema(
    schema: 'WebhookEndpointRequest',
    required: ['name', 'url', 'events'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Data warehouse'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', maxLength: 2000, example: 'https://hooks.example.com/fattura-facile', description: 'https only, default port 443 (or a port from 1024), no credentials in the URL. The host must resolve only to public internet addresses: private, loopback, link-local (cloud metadata included) and reserved ranges are refused when the endpoint is saved and again at every delivery. Redirects are never followed.'),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'events', type: 'array', minItems: 1, items: new OA\Items(type: 'string', example: 'invoicing.invoice.posted'), description: 'Event types from GET /api/v1/integrations/webhook-events'),
        new OA\Property(property: 'payload_version', type: 'string', example: 'v1', description: 'Payload version the endpoint is pinned to; the current default when omitted'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookEndpoint',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'disabled']),
        new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'payload_version', type: 'string', example: 'v1'),
        new OA\Property(property: 'secret_hint', type: 'string', example: 'a1B2', description: 'Last 4 characters of the signing secret, to tell secrets apart. The secret itself is never returned again.'),
        new OA\Property(property: 'secret', type: 'string', nullable: true, description: 'The signing secret. Present only in the response that creates the endpoint or rotates the secret: store it now.'),
        new OA\Property(property: 'previous_secret_expires_at', type: 'string', format: 'date-time', nullable: true, description: 'While in the future, payloads are signed with the previous secret too'),
        new OA\Property(property: 'consecutive_failures', type: 'integer'),
        new OA\Property(property: 'disabled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'disabled_reason', type: 'string', nullable: true),
        new OA\Property(property: 'last_success_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_failure_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDeliveryAttempt',
    properties: [
        new OA\Property(property: 'attempt_number', type: 'integer'),
        new OA\Property(property: 'succeeded', type: 'boolean'),
        new OA\Property(property: 'response_code', type: 'integer', nullable: true, description: 'HTTP status of the receiver; null when there was no answer'),
        new OA\Property(property: 'duration_ms', type: 'integer'),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'response_excerpt', type: 'string', nullable: true, description: 'First 1024 bytes of the response body'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDelivery',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'webhook_endpoint_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'event_id', type: 'string', format: 'uuid', description: 'Id of the event: the "id" of the payload, shared by the deliveries of the same event to different endpoints'),
        new OA\Property(property: 'event_name', type: 'string', example: 'invoicing.invoice.posted'),
        new OA\Property(property: 'idempotency_key', type: 'string', format: 'uuid', description: 'Sent as X-FF-Webhook-Id. The same on every retry and on manual redelivery: the receiver can deduplicate on it.'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'retrying', 'succeeded', 'dead'], description: 'dead = dead letter: out of attempts or its endpoint was disabled; redeliver it by hand'),
        new OA\Property(property: 'attempts', type: 'integer'),
        new OA\Property(property: 'next_attempt_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_test', type: 'boolean'),
        new OA\Property(property: 'last_response_code', type: 'integer', nullable: true),
        new OA\Property(property: 'last_error', type: 'string', nullable: true),
        new OA\Property(property: 'last_duration_ms', type: 'integer', nullable: true),
        new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'payload', ref: '#/components/schemas/WebhookPayload', description: 'Only in the detail response'),
        new OA\Property(property: 'attempt_log', type: 'array', items: new OA\Items(ref: '#/components/schemas/WebhookDeliveryAttempt'), description: 'Only in the detail response'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookPayload',
    description: 'The JSON document POSTed to the endpoint (payload version v1). Sent with the headers X-FF-Webhook-Id, X-FF-Webhook-Event, X-FF-Webhook-Version and X-FF-Webhook-Signature. Answer with any 2xx within 10 seconds; any other answer, a redirect included, is a failure and is retried with exponential backoff, then moved to the dead letters. Additive changes (new members) can appear within v1: ignore members you do not know.',
    required: ['id', 'type', 'api_version', 'created_at', 'tenant_id', 'data'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Event id'),
        new OA\Property(property: 'type', type: 'string', example: 'invoicing.invoice.posted', description: 'Event type; "webhook.ping" for the test event'),
        new OA\Property(property: 'api_version', type: 'string', example: 'v1'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', description: 'When the event occurred'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'data', ref: '#/components/schemas/WebhookEventData'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataInvoicingInvoicePosted',
    description: 'Member "data" of the event invoicing.invoice.posted. A sales invoice has been posted (issued) and is final.',
    required: ['invoice_id', 'invoice_number', 'customer_id', 'date', 'currency', 'exchange_rate', 'total_net', 'total_tax', 'total_gross', 'revenue_lines', 'tax_lines'],
    properties: [
        new OA\Property(property: 'invoice_id', type: 'string', description: 'Invoice id (UUID)'),
        new OA\Property(property: 'invoice_number', type: 'string', description: 'Invoice number'),
        new OA\Property(property: 'customer_id', type: 'string', description: 'Customer id (UUID)'),
        new OA\Property(property: 'date', type: 'string', description: 'Invoice date, Y-m-d'),
        new OA\Property(property: 'due_date', type: 'string', nullable: true, description: 'Due date, Y-m-d'),
        new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217 currency code'),
        new OA\Property(property: 'exchange_rate', type: 'number', description: 'Rate to the functional currency'),
        new OA\Property(property: 'total_net', type: 'number', description: 'Total before tax'),
        new OA\Property(property: 'total_tax', type: 'number', description: 'Total tax'),
        new OA\Property(property: 'total_gross', type: 'number', description: 'Total including tax'),
        new OA\Property(property: 'revenue_lines', type: 'array', items: new OA\Items(type: 'object'), description: 'Revenue lines posted to the ledger'),
        new OA\Property(property: 'tax_lines', type: 'array', items: new OA\Items(type: 'object'), description: 'Tax lines posted to the ledger'),
        new OA\Property(property: 'gl_journal_entry_id', type: 'string', nullable: true, description: 'Ledger entry id, when the posting already exists'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataSalesOrderApproved',
    description: 'Member "data" of the event sales.order.approved. A sales order has been approved (confirmed).',
    required: ['sales_order_id', 'approved_by'],
    properties: [
        new OA\Property(property: 'sales_order_id', type: 'string', description: 'Sales order id (UUID)'),
        new OA\Property(property: 'approved_by', type: 'string', description: 'Id of the user who approved it'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataSalesQuoteAccepted',
    description: 'Member "data" of the event sales.quote.accepted. A customer accepted a sales quote.',
    required: ['quote_id', 'total_amount'],
    properties: [
        new OA\Property(property: 'quote_id', type: 'string', description: 'Quote id (UUID)'),
        new OA\Property(property: 'sales_order_id', type: 'string', nullable: true, description: 'Order created from the quote, if any'),
        new OA\Property(property: 'quote_number', type: 'string', nullable: true, description: 'Quote number'),
        new OA\Property(property: 'customer_id', type: 'string', nullable: true, description: 'Customer id (UUID)'),
        new OA\Property(property: 'total_amount', type: 'number', description: 'Quote total'),
        new OA\Property(property: 'sales_quote_id', type: 'string', nullable: true, description: 'Sales quote id (UUID)'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataTreasuryCollectionReceived',
    description: 'Member "data" of the event treasury.collection.received. A collection from a customer has been registered. When it settles an invoice, invoice_id names it: this is the signal that an invoice has been paid.',
    required: ['collection_id', 'customer_id', 'collected_amount', 'collection_date'],
    properties: [
        new OA\Property(property: 'collection_id', type: 'string', description: 'Collection id (UUID)'),
        new OA\Property(property: 'customer_id', type: 'string', description: 'Customer id (UUID)'),
        new OA\Property(property: 'invoice_id', type: 'string', nullable: true, description: 'Invoice the collection settles, if any'),
        new OA\Property(property: 'collected_amount', type: 'number', description: 'Amount collected'),
        new OA\Property(property: 'collection_date', type: 'string', description: 'Collection date'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataTreasuryPaymentExecuted',
    description: 'Member "data" of the event treasury.payment.executed. A batch of supplier payments has been sent to the bank.',
    required: ['payment_batch_id', 'bank_account_id', 'total_amount', 'currency', 'protocol', 'bank_transmission_reference', 'executed_line_ids'],
    properties: [
        new OA\Property(property: 'payment_batch_id', type: 'string', description: 'Payment batch id (UUID)'),
        new OA\Property(property: 'bank_account_id', type: 'string', description: 'Bank account id (UUID)'),
        new OA\Property(property: 'total_amount', type: 'number', description: 'Total of the batch'),
        new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217 currency code'),
        new OA\Property(property: 'protocol', type: 'string', description: 'Transmission protocol'),
        new OA\Property(property: 'bank_transmission_reference', type: 'string', description: 'Reference given by the bank channel'),
        new OA\Property(property: 'executed_line_ids', type: 'array', items: new OA\Items(type: 'object'), description: 'Ids of the executed payment lines'),
        new OA\Property(property: 'value_date', type: 'string', nullable: true, description: 'Value date'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataInventoryReorderPointReached',
    description: 'Member "data" of the event inventory.reorder_point_reached. The stock of an item in a warehouse fell to its reorder point.',
    required: ['reorder_rule_id', 'stock_item_id', 'warehouse_id', 'current_stock', 'reorder_point', 'suggested_order_qty'],
    properties: [
        new OA\Property(property: 'reorder_rule_id', type: 'string', description: 'Reorder rule id (UUID)'),
        new OA\Property(property: 'stock_item_id', type: 'string', description: 'Stock item id (UUID)'),
        new OA\Property(property: 'warehouse_id', type: 'string', description: 'Warehouse id (UUID)'),
        new OA\Property(property: 'current_stock', type: 'number', description: 'Stock on hand'),
        new OA\Property(property: 'reorder_point', type: 'number', description: 'Threshold of the rule'),
        new OA\Property(property: 'suggested_order_qty', type: 'number', description: 'Quantity suggested for the order'),
        new OA\Property(property: 'preferred_supplier_id', type: 'string', nullable: true, description: 'Preferred supplier id (UUID)'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataEsmTicketCreated',
    description: 'Member "data" of the event esm.ticket.created. A service ticket has been opened.',
    required: ['ticket_id', 'ticket_number', 'title', 'priority', 'status'],
    properties: [
        new OA\Property(property: 'ticket_id', type: 'string', description: 'Ticket id (UUID)'),
        new OA\Property(property: 'ticket_number', type: 'string', description: 'Ticket number'),
        new OA\Property(property: 'title', type: 'string', description: 'Title'),
        new OA\Property(property: 'priority', type: 'string', description: 'Priority'),
        new OA\Property(property: 'status', type: 'string', description: 'Status'),
        new OA\Property(property: 'customer_id', type: 'string', nullable: true, description: 'Customer id (UUID)'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataCrmLeadCreated',
    description: 'Member "data" of the event crm.lead.created. A lead has been created in the CRM. It carries personal data of the lead: subscribe only if the receiver is entitled to it.',
    required: ['lead_id', 'full_name', 'score', 'status'],
    properties: [
        new OA\Property(property: 'lead_id', type: 'string', description: 'Lead id (UUID)'),
        new OA\Property(property: 'full_name', type: 'string', description: 'Full name'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true, description: 'Company name'),
        new OA\Property(property: 'email', type: 'string', nullable: true, description: 'Email address'),
        new OA\Property(property: 'score', type: 'integer', description: 'Lead score'),
        new OA\Property(property: 'status', type: 'string', description: 'Lead status'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataCrmOpportunityWon',
    description: 'Member "data" of the event crm.opportunity.won. A CRM opportunity has been marked as won.',
    required: ['opportunity_id', 'account_id', 'amount', 'currency', 'actor_user_id'],
    properties: [
        new OA\Property(property: 'opportunity_id', type: 'string', description: 'Opportunity id (UUID)'),
        new OA\Property(property: 'account_id', type: 'string', description: 'Account id (UUID)'),
        new OA\Property(property: 'contact_id', type: 'string', nullable: true, description: 'Contact id (UUID)'),
        new OA\Property(property: 'amount', type: 'number', description: 'Opportunity amount'),
        new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217 currency code'),
        new OA\Property(property: 'sales_quote_id', type: 'string', nullable: true, description: 'Linked quote id (UUID)'),
        new OA\Property(property: 'actor_user_id', type: 'string', description: 'Id of the user who won it'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataInvoicingInvoicePaid',
    description: 'Member "data" of the event invoicing.invoice.paid. A sales invoice has become fully settled (payments and applied credit notes cover what is due). Raised once per transition to paid.',
    required: ['invoice_id', 'invoice_number', 'currency', 'total_gross', 'total_paid', 'paid_at'],
    properties: [
        new OA\Property(property: 'invoice_id', type: 'string', description: 'Invoice id (UUID)'),
        new OA\Property(property: 'invoice_number', type: 'string', description: 'Invoice number'),
        new OA\Property(property: 'customer_id', type: 'string', nullable: true, description: 'Customer id (UUID)'),
        new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217 currency code'),
        new OA\Property(property: 'total_gross', type: 'number', description: 'Total including tax'),
        new OA\Property(property: 'total_paid', type: 'number', description: 'Amount settled by payments'),
        new OA\Property(property: 'paid_at', type: 'string', description: 'When the invoice became paid, ISO 8601'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataCrmContactCreated',
    description: 'Member "data" of the event crm.contact.created. A contact (customer, supplier or person) has been created, by any path: API, import, lead conversion. It carries personal data: subscribe only if the receiver is entitled to it.',
    required: ['contact_id', 'contact_type'],
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', description: 'Contact id (UUID)'),
        new OA\Property(property: 'contact_type', type: 'string', description: 'customer, supplier or another contact type'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true, description: 'Company name'),
        new OA\Property(property: 'first_name', type: 'string', nullable: true, description: 'First name'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true, description: 'Last name'),
        new OA\Property(property: 'email', type: 'string', nullable: true, description: 'Email address'),
        new OA\Property(property: 'country', type: 'string', nullable: true, description: 'ISO 3166-1 alpha-2 country'),
        new OA\Property(property: 'vat_number', type: 'string', nullable: true, description: 'VAT number'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataInventoryStockBelowMinimum',
    description: 'Member "data" of the event inventory.stock_below_minimum. The available stock of an item in a warehouse dropped below the minimum stock level of its reorder rule. Raised on the crossing only, not again while the stock stays below; distinct from the reorder point event.',
    required: ['reorder_rule_id', 'stock_item_id', 'warehouse_id', 'available_stock', 'minimum_stock'],
    properties: [
        new OA\Property(property: 'reorder_rule_id', type: 'string', description: 'Reorder rule id (UUID)'),
        new OA\Property(property: 'stock_item_id', type: 'string', description: 'Stock item id (UUID)'),
        new OA\Property(property: 'warehouse_id', type: 'string', description: 'Warehouse id (UUID)'),
        new OA\Property(property: 'available_stock', type: 'number', description: 'Available stock: on hand minus reserved, outside quarantine and transit'),
        new OA\Property(property: 'minimum_stock', type: 'number', description: 'Minimum stock level of the rule'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookDataProcurementPurchaseOrderApproved',
    description: 'Member "data" of the event procurement.purchase_order.approved. A purchase order has been approved.',
    required: ['purchase_order_id', 'po_number', 'supplier_id', 'total_amount'],
    properties: [
        new OA\Property(property: 'purchase_order_id', type: 'string', description: 'Purchase order id (UUID)'),
        new OA\Property(property: 'po_number', type: 'string', description: 'Purchase order number'),
        new OA\Property(property: 'supplier_id', type: 'string', description: 'Supplier id (UUID)'),
        new OA\Property(property: 'total_amount', type: 'number', description: 'Order total'),
    ]
)]
#[OA\Schema(
    schema: 'WebhookEventData',
    description: 'The "data" member of a webhook payload, by event type',
    oneOf: [
        new OA\Schema(ref: '#/components/schemas/WebhookDataInvoicingInvoicePosted'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataSalesOrderApproved'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataSalesQuoteAccepted'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataTreasuryCollectionReceived'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataTreasuryPaymentExecuted'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataInventoryReorderPointReached'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataEsmTicketCreated'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataCrmLeadCreated'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataCrmOpportunityWon'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataInvoicingInvoicePaid'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataCrmContactCreated'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataInventoryStockBelowMinimum'),
        new OA\Schema(ref: '#/components/schemas/WebhookDataProcurementPurchaseOrderApproved'),
    ]
)]
final class WebhookSchemas {}
