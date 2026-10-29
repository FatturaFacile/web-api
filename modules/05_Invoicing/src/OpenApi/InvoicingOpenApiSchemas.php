<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

final class InvoicingOpenApiSchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return [
            'SalesQuote' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'tenant_id' => ['type' => 'string', 'format' => 'uuid'],
                    'contact_id' => ['type' => 'string', 'format' => 'uuid'],
                    'quote_number' => ['type' => 'string'],
                    'date' => ['type' => 'string', 'format' => 'date'],
                    'valid_until' => ['type' => 'string', 'format' => 'date'],
                    'currency' => ['type' => 'string', 'example' => 'EUR'],
                    'subtotal' => ['type' => 'number', 'format' => 'float'],
                    'discount_amount' => ['type' => 'number', 'format' => 'float'],
                    'total_net' => ['type' => 'number', 'format' => 'float'],
                    'total_tax' => ['type' => 'number', 'format' => 'float'],
                    'total_gross' => ['type' => 'number', 'format' => 'float'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'approved', 'accepted', 'rejected', 'converted', 'expired']],
                ],
                'required' => ['id', 'tenant_id', 'contact_id', 'quote_number', 'total_gross'],
            ],
            'SalesOrder' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'tenant_id' => ['type' => 'string', 'format' => 'uuid'],
                    'contact_id' => ['type' => 'string', 'format' => 'uuid'],
                    'order_number' => ['type' => 'string'],
                    'date' => ['type' => 'string', 'format' => 'date'],
                    'due_date' => ['type' => 'string', 'format' => 'date'],
                    'currency' => ['type' => 'string', 'example' => 'EUR'],
                    'total_gross' => ['type' => 'number', 'format' => 'float'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'confirmed', 'in_fulfillment', 'shipped', 'completed', 'cancelled', 'on_hold']],
                    'credit_status' => ['type' => 'string', 'enum' => ['pending_check', 'approved', 'on_hold', 'rejected']],
                    'inventory_status' => ['type' => 'string', 'enum' => ['pending', 'reserved', 'partially_reserved', 'dispatched']],
                    'fulfillment_status' => ['type' => 'string', 'enum' => ['unfulfilled', 'partial', 'fulfilled']],
                    'billing_status' => ['type' => 'string', 'enum' => ['unbilled', 'partially_billed', 'billed']],
                ],
                'required' => ['id', 'tenant_id', 'contact_id', 'order_number', 'total_gross'],
            ],
            'SalesDeliveryNote' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'tenant_id' => ['type' => 'string', 'format' => 'uuid'],
                    'contact_id' => ['type' => 'string', 'format' => 'uuid'],
                    'sales_order_id' => ['type' => 'string', 'format' => 'uuid'],
                    'ddt_number' => ['type' => 'string'],
                    'date' => ['type' => 'string', 'format' => 'date'],
                    'shipping_status' => ['type' => 'string', 'enum' => ['draft', 'picked', 'packed', 'dispatched', 'delivered']],
                    'packages_count' => ['type' => 'integer'],
                    'gross_weight_kg' => ['type' => 'number', 'format' => 'float'],
                    'tracking_number' => ['type' => 'string', 'nullable' => true],
                ],
                'required' => ['id', 'tenant_id', 'ddt_number'],
            ],
            'SalesInvoice' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'tenant_id' => ['type' => 'string', 'format' => 'uuid'],
                    'contact_id' => ['type' => 'string', 'format' => 'uuid'],
                    'invoice_number' => ['type' => 'string'],
                    'invoice_type' => ['type' => 'string', 'enum' => ['standard', 'credit_note', 'debit_note', 'proforma']],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'approved', 'posted', 'paid', 'cancelled']],
                    'payment_status' => ['type' => 'string', 'enum' => ['unpaid', 'partially_paid', 'paid']],
                    'total_gross' => ['type' => 'number', 'format' => 'float'],
                    'receivable_amount' => ['type' => 'number', 'format' => 'float'],
                    'total_paid' => ['type' => 'number', 'format' => 'float'],
                ],
                'required' => ['id', 'tenant_id', 'contact_id', 'invoice_number', 'total_gross'],
            ],
            'CustomerCreditProfile' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'customer_id' => ['type' => 'string', 'format' => 'uuid'],
                    'credit_limit' => ['type' => 'number', 'format' => 'float'],
                    'used_credit' => ['type' => 'number', 'format' => 'float'],
                    'total_exposure' => ['type' => 'number', 'format' => 'float'],
                    'overdue_amount' => ['type' => 'number', 'format' => 'float'],
                    'credit_status' => ['type' => 'string', 'enum' => ['good', 'warning', 'hold', 'blocked']],
                    'risk_rating' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                    'is_credit_hold' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'customer_id', 'credit_limit', 'total_exposure'],
            ],
        ];
    }
}
