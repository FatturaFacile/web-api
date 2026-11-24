<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the subscription engine. Amounts are decimal numbers or strings.
 */
#[OA\Schema(schema: 'SubscriptionBillingCreatePlanRequest', required: ['plan_code', 'name', 'billing_interval', 'base_price'], properties: [
    new OA\Property(property: 'plan_code', type: 'string', maxLength: 50),
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'billing_interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual']),
    new OA\Property(property: 'interval_count', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'billing_alignment', type: 'string', enum: ['anniversary', 'calendar']),
    new OA\Property(property: 'pricing_model', type: 'string', enum: ['flat_fee', 'per_unit', 'per_seat', 'tiered', 'volume', 'metered', 'hybrid']),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'base_price', type: 'string', example: '99.00'),
    new OA\Property(property: 'setup_fee', type: 'string', example: '0.00'),
    new OA\Property(property: 'tax_rate', type: 'string', example: '22.00', nullable: true),
    new OA\Property(property: 'trial_period_days', type: 'integer'),
    new OA\Property(property: 'renewal_price_increase_percent', type: 'string', example: '3.0'),
    new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'deferred_revenue_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(required: ['from_quantity', 'unit_price'], properties: [
        new OA\Property(property: 'from_quantity', type: 'string'),
        new OA\Property(property: 'to_quantity', type: 'string', nullable: true),
        new OA\Property(property: 'unit_price', type: 'string'),
        new OA\Property(property: 'flat_fee', type: 'string'),
    ])),
    new OA\Property(property: 'features', type: 'array', items: new OA\Items(required: ['feature_code', 'name'], properties: [
        new OA\Property(property: 'feature_code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'metric_type', type: 'string', enum: ['boolean', 'metered_quota', 'unlimited']),
        new OA\Property(property: 'included_quantity', type: 'string'),
        new OA\Property(property: 'overage_unit_price', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'SubscriptionBillingUpdatePlanRequest', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'tax_rate', type: 'string', nullable: true),
    new OA\Property(property: 'trial_period_days', type: 'integer'),
    new OA\Property(property: 'renewal_price_increase_percent', type: 'string'),
    new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'deferred_revenue_account_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'SubscriptionBillingCreateSubscriptionRequest', required: ['customer_id', 'plan_id', 'start_date'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', description: 'A contact of the company'),
    new OA\Property(property: 'plan_id', type: 'string', format: 'uuid', description: 'An active plan of the company'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'string', example: '5', description: 'Seats of per-seat, tiered and volume plans'),
    new OA\Property(property: 'trial_days', type: 'integer', description: 'Overrides the trial of the plan'),
    new OA\Property(property: 'billing_alignment', type: 'string', enum: ['anniversary', 'calendar']),
    new OA\Property(property: 'contract_term_months', type: 'integer'),
    new OA\Property(property: 'auto_renew', type: 'boolean'),
    new OA\Property(property: 'renewal_notice_days', type: 'integer'),
    new OA\Property(property: 'cancellation_notice_days', type: 'integer'),
    new OA\Property(property: 'renewal_price_increase_percent', type: 'string', nullable: true),
    new OA\Property(property: 'payment_terms_days', type: 'integer'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['sepa_direct_debit', 'bank_transfer', 'credit_card', 'cash']),
    new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'item_type', type: 'string', enum: ['addon', 'support_retainer', 'hardware_lease', 'charge', 'usage_meter']),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string'),
        new OA\Property(property: 'discount_percentage', type: 'string'),
        new OA\Property(property: 'tax_rate', type: 'string'),
        new OA\Property(property: 'metric_id', type: 'string', format: 'uuid', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'SubscriptionBillingCancelSubscriptionRequest', required: ['reason'], properties: [
    new OA\Property(property: 'reason', type: 'string', maxLength: 255),
    new OA\Property(property: 'reason_code', type: 'string', enum: ['too_expensive', 'missing_features', 'switched_competitor', 'unused', 'customer_service', 'technical_issues', 'business_closed', 'non_payment', 'other']),
    new OA\Property(property: 'cancellation_type', type: 'string', enum: ['immediate', 'end_of_term'], default: 'immediate'),
    new OA\Property(property: 'cancellation_date', type: 'string', format: 'date', description: 'Immediate cancellations only'),
    new OA\Property(property: 'refund', type: 'boolean', default: true, description: 'Credit note for the unused days of the billed period'),
])]
#[OA\Schema(schema: 'SubscriptionBillingChangePlanRequest', required: ['new_plan_id'], properties: [
    new OA\Property(property: 'new_plan_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'quantity', type: 'string', nullable: true),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date'),
    new OA\Property(property: 'change_reason', type: 'string'),
])]
#[OA\Schema(schema: 'SubscriptionBillingChangeQuantityRequest', required: ['quantity'], properties: [
    new OA\Property(property: 'quantity', type: 'string', example: '10'),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date'),
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'SubscriptionBillingPauseRequest', properties: [
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'resume_on', type: 'string', format: 'date', description: 'The pause ends by itself on this date'),
])]
#[OA\Schema(schema: 'SubscriptionBillingRecordUsageRequest', required: ['subscription_item_id', 'metric_code', 'quantity'], properties: [
    new OA\Property(property: 'subscription_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'metric_code', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string', example: '120'),
    new OA\Property(property: 'event_timestamp', type: 'string', format: 'date-time'),
    new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 120),
])]
#[OA\Schema(schema: 'SubscriptionBillingExecuteBillingRunRequest', properties: [
    new OA\Property(property: 'run_date', type: 'string', format: 'date'),
    new OA\Property(property: 'lookahead_days', type: 'integer', minimum: 0, maximum: 90),
    new OA\Property(property: 'subscription_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'SubscriptionBillingRecognizeRevenueRequest', properties: [
    new OA\Property(property: 'period_date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'SubscriptionBillingCollectionFailureRequest', required: ['invoice_id'], properties: [
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'SubscriptionBillingDunningPolicyRequest', required: ['policy_name', 'soft_reminder_days', 'formal_notice_days', 'suspension_days', 'termination_days'], properties: [
    new OA\Property(property: 'policy_name', type: 'string'),
    new OA\Property(property: 'soft_reminder_days', type: 'integer'),
    new OA\Property(property: 'formal_notice_days', type: 'integer'),
    new OA\Property(property: 'suspension_days', type: 'integer'),
    new OA\Property(property: 'termination_days', type: 'integer'),
    new OA\Property(property: 'max_collection_retries', type: 'integer'),
    new OA\Property(property: 'retry_interval_days', type: 'integer'),
])]
final class SubscriptionBillingRequestSchemas {}
