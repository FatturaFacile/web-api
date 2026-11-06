<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'SubscriptionBillingCreatePlanRequest',
    description: 'Request payload validated by CreatePlanRequest',
    required: ['plan_code', 'name', 'billing_interval', 'base_price'],
    type: 'object',
    properties: [
        new OA\Property(property: 'plan_code', type: 'string', maxLength: 50),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'billing_interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual', 'multi_year']),
        new OA\Property(property: 'interval_count', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'pricing_model', type: 'string', nullable: true, enum: ['flat_fee', 'per_unit', 'tiered', 'volume', 'metered', 'hybrid']),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'setup_fee', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'base_price', type: 'number', minimum: 0),
        new OA\Property(property: 'trial_period_days', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'min_commitment_cycles', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'tiers', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'features', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'translations', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingRecordUsageRequest',
    description: 'Request payload validated by RecordUsageRequest',
    required: ['subscription_item_id', 'metric_code', 'quantity'],
    type: 'object',
    properties: [
        new OA\Property(property: 'subscription_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'metric_code', type: 'string', maxLength: 50),
        new OA\Property(property: 'quantity', type: 'number', minimum: 0),
        new OA\Property(property: 'event_timestamp', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingExecuteBillingRunRequest',
    description: 'Request payload validated by ExecuteBillingRunRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'run_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'lookahead_days', type: 'integer', minimum: 0, maximum: 90, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingRecognizeRevenueRequest',
    description: 'Request payload validated by RecognizeRevenueRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'period_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingUpgradePlanRequest',
    description: 'Request payload validated by UpgradePlanRequest',
    required: ['new_plan_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'new_plan_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'change_reason', type: 'string', maxLength: 255, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingDowngradePlanRequest',
    description: 'Request payload validated by DowngradePlanRequest',
    required: ['new_plan_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'new_plan_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'change_reason', type: 'string', maxLength: 255, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingCreateSubscriptionRequest',
    description: 'Request payload validated by CreateSubscriptionRequest',
    required: ['customer_id', 'plan_id', 'start_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'plan_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'contract_term_months', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'auto_renew', type: 'boolean', nullable: true),
        new OA\Property(property: 'billing_day_of_month', type: 'integer', minimum: 1, maximum: 31, nullable: true),
        new OA\Property(property: 'payment_terms_days', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: ['sepa_direct_debit', 'bank_transfer', 'credit_card', 'cash']),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'items', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'item_type', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'quantity', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true)])),
        new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingCancelSubscriptionRequest',
    description: 'Request payload validated by CancelSubscriptionRequest',
    required: ['reason'],
    type: 'object',
    properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 255),
        new OA\Property(property: 'cancellation_date', type: 'string', format: 'date', nullable: true),
    ]
)]
final class SubscriptionBillingRequestSchemas {}
