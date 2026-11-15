<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas of the full point of sale of the Fiscal Retail module: promotions, tickets, cash drawer, returns and the X and
 * Z reports. Money is a decimal string.
 */
#[OA\Schema(schema: 'RetailPromotion', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'SUMMER10'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'type', type: 'string', enum: ['percentage', 'fixed', 'buy_x_get_y']),
    new OA\Property(property: 'value', type: 'string', example: '10.0000', description: 'Percentage or amount off, net of VAT'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true, description: 'Limits the promotion to one product; required for buy_x_get_y'),
    new OA\Property(property: 'buy_quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'get_quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'min_subtotal', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'weekdays', type: 'array', items: new OA\Items(type: 'integer', minimum: 1, maximum: 7), description: 'ISO weekdays (1 Monday); empty means every day'),
    new OA\Property(property: 'time_from', type: 'string', nullable: true, example: '17:00'),
    new OA\Property(property: 'time_to', type: 'string', nullable: true, example: '19:30'),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_stackable', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'RetailPromotionRequest', required: ['code', 'name', 'type'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'type', type: 'string', enum: ['percentage', 'fixed', 'buy_x_get_y']),
    new OA\Property(property: 'value', type: 'number', nullable: true),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'buy_quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'get_quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'min_subtotal', type: 'number', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'weekdays', type: 'array', nullable: true, items: new OA\Items(type: 'integer', minimum: 1, maximum: 7)),
    new OA\Property(property: 'time_from', type: 'string', nullable: true, example: '17:00'),
    new OA\Property(property: 'time_to', type: 'string', nullable: true, example: '19:30'),
    new OA\Property(property: 'priority', type: 'integer', nullable: true),
    new OA\Property(property: 'is_stackable', type: 'boolean', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'RetailTicketItem', required: ['quantity'], properties: [
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Required without product_id'),
    new OA\Property(property: 'quantity', type: 'number'),
    new OA\Property(property: 'unit_price', type: 'number', nullable: true, description: 'Net of VAT; defaults to the product sale price'),
    new OA\Property(property: 'tax_rate', type: 'number', nullable: true, description: 'Defaults to the product VAT rate'),
])]
#[OA\Schema(schema: 'RetailTicketItemsRequest', required: ['items'], properties: [
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailTicketItem')),
])]
#[OA\Schema(schema: 'RetailPayment', required: ['method', 'amount'], properties: [
    new OA\Property(property: 'method', type: 'string', enum: ['cash', 'card', 'voucher', 'bank_transfer', 'mobile', 'other']),
    new OA\Property(property: 'amount', type: 'number', description: 'Cash may exceed what is due: the excess is the change'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'RetailTicketRequest', required: ['pos_session_id', 'items'], properties: [
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailTicketItem')),
    new OA\Property(property: 'payments', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/RetailPayment')),
])]
#[OA\Schema(schema: 'RetailTicketPreview', properties: [
    new OA\Property(property: 'data', properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'promotions', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'promotion_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'discount_net', type: 'string'),
        ])),
        new OA\Property(property: 'discount_total', type: 'string'),
        new OA\Property(property: 'total_net', type: 'string'),
        new OA\Property(property: 'total_tax', type: 'string'),
        new OA\Property(property: 'total_gross', type: 'string'),
    ], type: 'object'),
])]
#[OA\Schema(schema: 'RetailTicket', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'receipt_number', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['completed', 'refunded']),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'total_net', type: 'string'),
    new OA\Property(property: 'total_tax', type: 'string'),
    new OA\Property(property: 'total_gross', type: 'string'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'card', 'mixed', 'prepaid']),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string', description: 'Negative on promotion discount lines'),
        new OA\Property(property: 'tax_rate', type: 'string'),
        new OA\Property(property: 'line_total', type: 'string'),
        new OA\Property(property: 'returnable_quantity', type: 'string', description: 'Only on the show endpoint'),
    ])),
    new OA\Property(property: 'payments', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'method', type: 'string', description: 'cash, card, voucher, bank_transfer, mobile, other or return_credit'),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'promotions', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'discount_net', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'RetailCashMovement', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'type', type: 'string', enum: ['pay_in', 'pay_out', 'safe_drop']),
    new OA\Property(property: 'amount', type: 'string'),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RetailCashMovementRequest', required: ['type', 'amount'], properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['pay_in', 'pay_out', 'safe_drop']),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Required for pay_out'),
])]
#[OA\Schema(schema: 'RetailDrawerSummary', properties: [
    new OA\Property(property: 'opening_float', type: 'string'),
    new OA\Property(property: 'cash_sales', type: 'string'),
    new OA\Property(property: 'pay_in', type: 'string'),
    new OA\Property(property: 'pay_out', type: 'string'),
    new OA\Property(property: 'safe_drop', type: 'string'),
    new OA\Property(property: 'cash_refunds', type: 'string'),
    new OA\Property(property: 'expected_cash', type: 'string'),
])]
#[OA\Schema(schema: 'RetailReturn', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', example: 'RET-2027-0001'),
    new OA\Property(property: 'pos_sale_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'exchange_sale_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'commercial_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'refund_method', type: 'string', enum: ['cash', 'card', 'other']),
    new OA\Property(property: 'total_net', type: 'string'),
    new OA\Property(property: 'total_tax', type: 'string'),
    new OA\Property(property: 'total_gross', type: 'string'),
    new OA\Property(property: 'credit_applied', type: 'string', description: 'Value of the returned goods used to pay the exchange ticket'),
    new OA\Property(property: 'cash_refunded', type: 'string'),
    new OA\Property(property: 'other_refunded', type: 'string'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'RetailReturnRequest', required: ['pos_session_id', 'lines', 'refund_method'], properties: [
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid', description: 'Open session that settles the refund'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', required: ['pos_sale_item_id', 'quantity'], properties: [
        new OA\Property(property: 'pos_sale_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'number'),
    ])),
    new OA\Property(property: 'refund_method', type: 'string', enum: ['cash', 'card', 'other']),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'exchange', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailTicketItem')),
        new OA\Property(property: 'payments', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailPayment')),
    ]),
])]
#[OA\Schema(schema: 'RetailSessionTotals', properties: [
    new OA\Property(property: 'session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'kind', type: 'string', enum: ['x', 'z']),
    new OA\Property(property: 'number', type: 'string', nullable: true),
    new OA\Property(property: 'business_date', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'closed']),
    new OA\Property(property: 'sales_count', type: 'integer'),
    new OA\Property(property: 'sales_net', type: 'string'),
    new OA\Property(property: 'sales_tax', type: 'string'),
    new OA\Property(property: 'sales_gross', type: 'string'),
    new OA\Property(property: 'discounts_net', type: 'string'),
    new OA\Property(property: 'returns_count', type: 'integer'),
    new OA\Property(property: 'returns_gross', type: 'string'),
    new OA\Property(property: 'net_gross', type: 'string', description: 'Sales less returns'),
    new OA\Property(property: 'payments_by_method', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'vat_breakdown', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'tax_rate', type: 'string'),
        new OA\Property(property: 'net', type: 'string'),
        new OA\Property(property: 'tax', type: 'string'),
        new OA\Property(property: 'gross', type: 'string'),
    ])),
    new OA\Property(property: 'cash', allOf: [new OA\Schema(ref: '#/components/schemas/RetailDrawerSummary')], description: 'Drawer summary with counted_cash and variance once closed'),
])]
#[OA\Schema(schema: 'RetailSessionReport', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'kind', type: 'string', enum: ['x', 'z']),
    new OA\Property(property: 'number', type: 'string', nullable: true, example: 'Z-2027-0001'),
    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'business_date', type: 'string', format: 'date'),
    new OA\Property(property: 'totals', allOf: [new OA\Schema(ref: '#/components/schemas/RetailSessionTotals')], description: 'Z reports add reconciliation: documented_gross, undocumented_gross and the state of the daily corrispettivi'),
])]
final class RetailPosOpenApi {}
