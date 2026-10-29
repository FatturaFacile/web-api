<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'SubscriptionBilling',
    description: 'Enterprise Subscription Billing, Recurring Revenue Management, Usage Metering, and IFRS 15 / ASC 606 Revenue Recognition'
)]
#[OA\Schema(
    schema: 'SubscriptionResource',
    title: 'Subscription Contract Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'subscription_number', type: 'string', example: 'SUB-20261029-ABC123'),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending', 'active', 'paused', 'past_due', 'cancelling', 'cancelled', 'expired']),
        new OA\Property(property: 'mrr', type: 'number', format: 'double', example: 1200.00),
        new OA\Property(property: 'arr', type: 'number', format: 'double', example: 14400.00),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    ]
)]
#[OA\Schema(
    schema: 'PlanResource',
    title: 'Subscription Plan Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'plan_code', type: 'string', example: 'SAAS-ENTERPRISE-ANNUAL'),
        new OA\Property(property: 'name', type: 'string', example: 'Enterprise Cloud Platform'),
        new OA\Property(property: 'billing_interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual', 'multi_year']),
        new OA\Property(property: 'pricing_model', type: 'string', enum: ['flat_fee', 'per_unit', 'tiered', 'volume', 'metered', 'hybrid']),
        new OA\Property(property: 'base_price', type: 'number', format: 'double', example: 990.00),
    ]
)]
#[OA\Schema(
    schema: 'RevenueContractResource',
    title: 'IFRS 15 Revenue Contract Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_number', type: 'string', example: 'REV-CTR-20261029-XYZ789'),
        new OA\Property(property: 'total_transaction_price', type: 'number', format: 'double', example: 14400.00),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'modified', 'completed', 'cancelled']),
    ]
)]
final class SubscriptionBillingOpenApiSchemas {}
