<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Payloads and parameter of the deprecated recurring billing API (/api/v1/subscriptions), served by the subscription engine.
 */
#[OA\Schema(
    schema: 'LegacySubscription',
    description: 'Subscription in the shape of the deprecated recurring billing API',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'subscription_number', type: 'string', example: 'SUB-2026-0001'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'trialing', 'past_due', 'paused', 'suspended', 'cancelled', 'expired']),
        new OA\Property(property: 'interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'yearly']),
        new OA\Property(property: 'interval_count', type: 'integer'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_billing_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'auto_renew', type: 'boolean'),
        new OA\Property(property: 'notify_days_before', type: 'integer'),
        new OA\Property(property: 'contact', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string')]),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'runs', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegacySubscriptionRun')),
    ]
)]
#[OA\Schema(
    schema: 'LegacySubscriptionRun',
    description: 'A billed (or failed) period in the shape of the deprecated billing runs',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['success', 'failed']),
        new OA\Property(property: 'error', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'LegacySubscriptionRequest',
    required: ['contact_id', 'name', 'interval', 'currency', 'start_date', 'items'],
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'yearly']),
        new OA\Property(property: 'interval_count', type: 'integer', minimum: 1, maximum: 12),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'auto_renew', type: 'boolean'),
        new OA\Property(property: 'notify_days_before', type: 'integer', minimum: 0, maximum: 365),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(required: ['description', 'quantity', 'unit_price', 'tax_rate'], properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'quantity', type: 'number'),
            new OA\Property(property: 'unit_price', type: 'number'),
            new OA\Property(property: 'tax_rate', type: 'number'),
            new OA\Property(property: 'discount_percentage', type: 'number'),
        ])),
    ]
)]
final class LegacySubscriptionSchemas {}
