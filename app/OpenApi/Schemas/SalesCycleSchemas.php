<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * OpenAPI schemas of the sales cycle endpoints. Amounts and quantities are decimal strings.
 */
#[OA\Schema(
    schema: 'SalesCycleCancelOrderRequest',
    required: ['reason'],
    properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Customer withdrew the order')]
)]
#[OA\Schema(
    schema: 'SalesCycleWarehouseRequest',
    properties: [new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true, description: 'Warehouse of the active company; the primary warehouse when omitted')]
)]
#[OA\Schema(
    schema: 'SalesCycleDeliveryNoteRequest',
    description: 'Lines to ship and transport data of the delivery note (DDT). The transport data is printed as given.',
    properties: [
        new OA\Property(property: 'lines', type: 'object', nullable: true, description: 'Order line id => quantity to ship; every open quantity when omitted (orders only)', example: ['0190a1b2-0000-7000-8000-000000000001' => 4], additionalProperties: new OA\AdditionalProperties(type: 'number')),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true, description: 'Warehouse the goods leave from; the primary warehouse when omitted'),
        new OA\Property(property: 'transport_reason', type: 'string', nullable: true, example: 'Vendita', description: 'Causale del trasporto'),
        new OA\Property(property: 'goods_appearance', type: 'string', nullable: true, example: 'Cartoni', description: 'Aspetto esteriore dei beni'),
        new OA\Property(property: 'packages_count', type: 'integer', nullable: true, example: 3, description: 'Number of packages (colli)'),
        new OA\Property(property: 'gross_weight_kg', type: 'number', nullable: true, example: 42.5),
        new OA\Property(property: 'net_weight_kg', type: 'number', nullable: true, example: 40.0),
        new OA\Property(property: 'carrier_name', type: 'string', nullable: true, example: 'BRT S.p.A.', description: 'Vettore'),
        new OA\Property(property: 'carrier_license_plate', type: 'string', nullable: true),
        new OA\Property(property: 'departure_time', type: 'string', format: 'date-time', nullable: true, description: 'Start of transport; the dispatch time when omitted'),
        new OA\Property(property: 'shipping_terms', type: 'string', nullable: true, example: 'Franco', description: 'Porto, as free text'),
        new OA\Property(property: 'transport_by', type: 'string', enum: ['sender', 'recipient', 'carrier'], nullable: true, description: 'Who carries the goods (trasporto a cura del mittente, destinatario o vettore)'),
        new OA\Property(property: 'courier', type: 'string', nullable: true),
        new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'show_prices', type: 'boolean', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleAvailabilityLine',
    properties: [
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'stock_relevant', type: 'boolean', description: 'The product tracks inventory; other lines need no stock'),
        new OA\Property(property: 'quantity_ordered', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_delivered', type: 'string', example: '4.0000'),
        new OA\Property(property: 'quantity_open', type: 'string', example: '6.0000', description: 'Backorder of the line: ordered less delivered'),
        new OA\Property(property: 'quantity_reserved', type: 'string', example: '2.0000', description: 'Reserved for this line in the inventory module'),
        new OA\Property(property: 'quantity_available', type: 'string', example: '3.0000', description: 'What the warehouse can still give (on hand less every reservation)'),
        new OA\Property(property: 'quantity_promisable', type: 'string', example: '5.0000', description: 'Available plus reserved for the line, up to the open quantity'),
        new OA\Property(property: 'shortage', type: 'string', example: '1.0000'),
        new OA\Property(property: 'fully_available', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleAvailability',
    properties: [
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'fully_available', type: 'boolean'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleAvailabilityLine')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleBackorderLine',
    properties: [
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_number', type: 'string', example: 'SO-2026-0007'),
        new OA\Property(property: 'order_date', type: 'string', format: 'date'),
        new OA\Property(property: 'order_status', type: 'string', example: 'in_fulfillment'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity_ordered', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_delivered', type: 'string', example: '4.0000'),
        new OA\Property(property: 'quantity_open', type: 'string', example: '6.0000'),
        new OA\Property(property: 'quantity_reserved', type: 'string', example: '6.0000'),
        new OA\Property(property: 'open_net_value', type: 'string', example: '600.00'),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleReviseQuoteRequest',
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true, description: 'Date of the revision; today when omitted'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'custom_text', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', description: 'New lines; the lines of the revised quote when omitted', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'description', type: 'string', example: 'Widget'),
            new OA\Property(property: 'quantity', type: 'number', example: 10),
            new OA\Property(property: 'unit_price', type: 'number', example: 95),
            new OA\Property(property: 'tax_rate', type: 'number', example: 22),
            new OA\Property(property: 'discount_percentage', type: 'number', example: 5, nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleAppliedDiscount',
    properties: [
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', nullable: true, example: 'SPRING10'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'priority', type: 'integer', example: 10),
        new OA\Property(property: 'percentage', type: 'string', example: '10.00'),
        new OA\Property(property: 'fixed_amount_per_unit', type: 'string', example: '0.0000'),
        new OA\Property(property: 'explicit', type: 'boolean', description: 'Named on the document rather than applied by itself'),
    ]
)]
#[OA\Schema(
    schema: 'SalesCyclePriceDetermination',
    properties: [
        new OA\Property(property: 'list_price', type: 'string', example: '100.0000'),
        new OA\Property(property: 'price_source', type: 'string', enum: ['price_list', 'product']),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'unit_price', type: 'string', example: '98.0000', description: 'List price less the fixed discounts per unit: the unit price of the line'),
        new OA\Property(property: 'discount_percentage', type: 'string', example: '10.00', description: 'Discount percentage of the line'),
        new OA\Property(property: 'fixed_discount_per_unit', type: 'string', example: '2.0000'),
        new OA\Property(property: 'net_unit_price', type: 'string', example: '88.2000'),
        new OA\Property(property: 'applied_discounts', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleAppliedDiscount')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleDiscountRule',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', readOnly: true),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer_group_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'min_threshold', type: 'string', nullable: true, example: '10.00', description: 'Minimum quantity of the line'),
        new OA\Property(property: 'discount_percent', type: 'string', nullable: true, example: '5.00'),
        new OA\Property(property: 'discount_fixed_amount', type: 'string', nullable: true, example: '0.00'),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleDiscountPolicy',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: 'SPRING10'),
        new OA\Property(property: 'name', type: 'string', example: 'Spring promotion'),
        new OA\Property(property: 'discount_type', type: 'string', enum: ['percentage', 'fixed_amount']),
        new OA\Property(property: 'value', type: 'string', example: '10.0000'),
        new OA\Property(property: 'min_order_amount', type: 'string', nullable: true),
        new OA\Property(property: 'min_quantity', type: 'string', nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Lower numbers apply first'),
        new OA\Property(property: 'is_stackable', type: 'boolean', description: 'False: applies only when first, then stops the others'),
        new OA\Property(property: 'auto_apply', type: 'boolean', description: 'Applies by itself to every matching line, without being named on the document'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true, description: 'Promotion reserved to one customer'),
        new OA\Property(property: 'rules', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleDiscountRule')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleDiscountPolicyRequest',
    required: ['code', 'name', 'discount_type', 'value'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 50, example: 'SPRING10'),
        new OA\Property(property: 'name', type: 'string', example: 'Spring promotion'),
        new OA\Property(property: 'discount_type', type: 'string', enum: ['percentage', 'fixed_amount']),
        new OA\Property(property: 'value', type: 'number', example: 10),
        new OA\Property(property: 'min_order_amount', type: 'number', nullable: true, example: 500),
        new OA\Property(property: 'min_quantity', type: 'number', nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true, example: '2026-03-01'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true, example: '2026-05-31'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'priority', type: 'integer', example: 10),
        new OA\Property(property: 'is_stackable', type: 'boolean', example: true),
        new OA\Property(property: 'auto_apply', type: 'boolean', example: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rules', type: 'array', description: 'Replace the rules when given; each rule targets a product, a customer or a customer group', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'customer_group_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'min_threshold', type: 'number', nullable: true),
            new OA\Property(property: 'discount_percent', type: 'number', nullable: true, example: 5),
            new OA\Property(property: 'discount_fixed_amount', type: 'number', nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleReturnLine',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'delivery_note_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'invoice_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string', example: '2.0000'),
        new OA\Property(property: 'quantity_received', type: 'string', example: '2.0000'),
        new OA\Property(property: 'unit_price', type: 'string', example: '100.0000'),
        new OA\Property(property: 'tax_rate', type: 'string', example: '22.00'),
        new OA\Property(property: 'discount_percentage', type: 'string', example: '0.00'),
        new OA\Property(property: 'condition', type: 'string', enum: ['resellable', 'damaged'], nullable: true),
        new OA\Property(property: 'restock', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleReturn',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'return_number', type: 'string', example: 'RMA-2026-0001'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['requested', 'approved', 'received', 'credited', 'rejected', 'cancelled']),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
        new OA\Property(property: 'credit_note_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'credited_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleReturnLine')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleReturnRequest',
    required: ['reason', 'items'],
    properties: [
        new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true, description: 'Delivery note the goods came with (or invoice_id)'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true, description: 'Invoice the goods were billed with (or delivery_note_id)'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reason', type: 'string', example: 'Wrong size'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'delivery_note_item_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'invoice_item_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'quantity', type: 'number', example: 2),
            new OA\Property(property: 'condition', type: 'string', enum: ['resellable', 'damaged'], nullable: true),
            new OA\Property(property: 'restock', type: 'boolean', nullable: true, example: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleReceiveReturnRequest',
    properties: [
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'items', type: 'array', description: 'Received quantities per line; every line in full when omitted', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quantity_received', type: 'number', example: 2),
            new OA\Property(property: 'condition', type: 'string', enum: ['resellable', 'damaged'], nullable: true),
            new OA\Property(property: 'restock', type: 'boolean', nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SalesCycleRejectReturnRequest',
    required: ['reason'],
    properties: [new OA\Property(property: 'reason', type: 'string', example: 'Returned after the agreed period')]
)]
#[OA\Schema(
    schema: 'SalesCycleKpis',
    properties: [
        new OA\Property(property: 'period', properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')], type: 'object'),
        new OA\Property(property: 'order_backlog', properties: [
            new OA\Property(property: 'open_orders', type: 'integer', example: 12),
            new OA\Property(property: 'open_lines', type: 'integer', example: 30),
            new OA\Property(property: 'open_net_value', type: 'string', example: '15420.00'),
        ], type: 'object'),
        new OA\Property(property: 'fill_rate', properties: [
            new OA\Property(property: 'orders', type: 'integer'),
            new OA\Property(property: 'lines', type: 'integer'),
            new OA\Property(property: 'lines_fully_delivered', type: 'integer'),
            new OA\Property(property: 'line_fill_rate', type: 'string', nullable: true, example: '87.50'),
            new OA\Property(property: 'quantity_ordered', type: 'string'),
            new OA\Property(property: 'quantity_delivered', type: 'string'),
            new OA\Property(property: 'quantity_fill_rate', type: 'string', nullable: true, example: '92.10'),
        ], type: 'object'),
        new OA\Property(property: 'quote_conversion', properties: [
            new OA\Property(property: 'quotes', type: 'integer'),
            new OA\Property(property: 'accepted', type: 'integer'),
            new OA\Property(property: 'rejected', type: 'integer'),
            new OA\Property(property: 'expired', type: 'integer'),
            new OA\Property(property: 'open', type: 'integer'),
            new OA\Property(property: 'converted_to_order', type: 'integer'),
            new OA\Property(property: 'quoted_net_value', type: 'string'),
            new OA\Property(property: 'accepted_net_value', type: 'string'),
            new OA\Property(property: 'conversion_rate', type: 'string', nullable: true, example: '40.00', description: 'Accepted over decided (accepted, rejected, expired), in %'),
            new OA\Property(property: 'order_conversion_rate', type: 'string', nullable: true, example: '33.33', description: 'Quotes converted into an order over all quotes, in %'),
        ], type: 'object'),
        new OA\Property(property: 'returns', properties: [
            new OA\Property(property: 'opened', type: 'integer'),
            new OA\Property(property: 'credited', type: 'integer'),
        ], type: 'object'),
    ]
)]
final class SalesCycleSchemas {}
