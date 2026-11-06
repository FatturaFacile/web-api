<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'SubscriptionBillingPlanResource',
    description: 'JSON representation produced by PlanResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'plan_code'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'billing_interval'),
        new OA\Property(property: 'interval_count', type: 'integer'),
        new OA\Property(property: 'pricing_model'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'setup_fee', type: 'number'),
        new OA\Property(property: 'base_price', type: 'number'),
        new OA\Property(property: 'trial_period_days', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingUsageEventResource',
    description: 'JSON representation produced by UsageEventResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'subscription_id', type: 'string'),
        new OA\Property(property: 'subscription_item_id', type: 'string'),
        new OA\Property(property: 'metric_code'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'event_timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_processed', type: 'boolean'),
        new OA\Property(property: 'processed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingBillingRunResource',
    description: 'JSON representation produced by BillingRunResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'run_number'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_subscriptions_evaluated', type: 'integer'),
        new OA\Property(property: 'successful_invoices_count', type: 'integer'),
        new OA\Property(property: 'failed_invoices_count', type: 'integer'),
        new OA\Property(property: 'total_invoiced_amount', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingRevenueContractResource',
    description: 'JSON representation produced by RevenueContractResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'contract_number'),
        new OA\Property(property: 'subscription_id', type: 'string'),
        new OA\Property(property: 'total_transaction_price', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'inception_date', type: 'string', format: 'date'),
        new OA\Property(property: 'termination_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'SubscriptionBillingSubscriptionResource',
    description: 'JSON representation produced by SubscriptionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'company_id', type: 'string'),
        new OA\Property(property: 'subscription_number'),
        new OA\Property(property: 'customer_id', type: 'string'),
        new OA\Property(property: 'plan_id', type: 'string'),
        new OA\Property(property: 'plan', ref: '#/components/schemas/SubscriptionBillingPlanResource'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'current_period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'current_period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'renewal_type'),
        new OA\Property(property: 'contract_term_months', type: 'integer'),
        new OA\Property(property: 'auto_renew', type: 'boolean'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'mrr', type: 'number'),
        new OA\Property(property: 'arr', type: 'number'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'items_count', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class SubscriptionBillingResponseSchemas {}
