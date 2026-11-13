<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the subscription engine, as produced by its API resources. Amounts are decimal strings.
 */
#[OA\Schema(schema: 'SubscriptionBillingPlanResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'plan_code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'billing_interval', type: 'string'),
    new OA\Property(property: 'interval_count', type: 'integer'),
    new OA\Property(property: 'billing_alignment', type: 'string'),
    new OA\Property(property: 'pricing_model', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'base_price', type: 'string'),
    new OA\Property(property: 'setup_fee', type: 'string'),
    new OA\Property(property: 'tax_rate', type: 'string', nullable: true),
    new OA\Property(property: 'trial_period_days', type: 'integer'),
    new OA\Property(property: 'renewal_price_increase_percent', type: 'string'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SubscriptionBillingSubscriptionResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subscription_number', type: 'string', example: 'SUB-2026-0001'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'plan_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'plan_name', type: 'string', nullable: true, description: 'Present when the plan is loaded (contract list)'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true, description: 'Present when the customer is loaded (contract list)'),
    new OA\Property(property: 'status', type: 'string', enum: ['trialing', 'active', 'past_due', 'paused', 'suspended', 'pending', 'cancelled', 'expired']),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'trial_end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'current_period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'current_period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'next_billing_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'term_end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'auto_renew', type: 'boolean'),
    new OA\Property(property: 'cancel_at_period_end', type: 'boolean'),
    new OA\Property(property: 'cancellation_reason_code', type: 'string', nullable: true),
    new OA\Property(property: 'cancellation_effective_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'mrr', type: 'string'),
    new OA\Property(property: 'arr', type: 'string'),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'versions', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SubscriptionBillingUsageEventResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subscription_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subscription_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'metric_code', type: 'string'),
    new OA\Property(property: 'idempotency_key', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'event_timestamp', type: 'string', format: 'date-time'),
    new OA\Property(property: 'is_processed', type: 'boolean'),
])]
#[OA\Schema(schema: 'SubscriptionBillingBillingRunResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'run_number', type: 'string'),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'total_subscriptions_evaluated', type: 'integer'),
    new OA\Property(property: 'successful_invoices_count', type: 'integer'),
    new OA\Property(property: 'failed_invoices_count', type: 'integer'),
    new OA\Property(property: 'total_invoiced_amount', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['processing', 'completed', 'completed_with_errors', 'failed']),
    new OA\Property(property: 'errors_log', type: 'array', nullable: true, items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SubscriptionBillingRevenueContractResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contract_number', type: 'string'),
    new OA\Property(property: 'subscription_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'total_transaction_price', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'performance_obligations', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SubscriptionBillingInvoiceScheduleResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'billing_kind', type: 'string', enum: ['recurring', 'proration', 'credit']),
    new OA\Property(property: 'status', type: 'string', enum: ['processing', 'invoiced', 'failed', 'skipped', 'synthetic']),
    new OA\Property(property: 'service_period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'service_period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'net_amount', type: 'string'),
    new OA\Property(property: 'gross_amount', type: 'string'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'invoice_number', type: 'string', nullable: true),
    new OA\Property(property: 'payment_status', type: 'string', nullable: true),
    new OA\Property(property: 'credit_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'error', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SubscriptionBillingDunningRecordResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'dunning_level', type: 'integer'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'action_taken', type: 'string'),
    new OA\Property(property: 'retry_count', type: 'integer'),
    new OA\Property(property: 'next_retry_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'days_overdue', type: 'integer'),
])]
#[OA\Schema(schema: 'SubscriptionBillingDunningPolicyResource', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'policy_name', type: 'string'),
    new OA\Property(property: 'soft_reminder_days', type: 'integer'),
    new OA\Property(property: 'formal_notice_days', type: 'integer'),
    new OA\Property(property: 'suspension_days', type: 'integer'),
    new OA\Property(property: 'termination_days', type: 'integer'),
    new OA\Property(property: 'max_collection_retries', type: 'integer'),
    new OA\Property(property: 'retry_interval_days', type: 'integer'),
])]
#[OA\Schema(schema: 'SubscriptionBillingMetrics', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'mrr', type: 'string'),
    new OA\Property(property: 'arr', type: 'string'),
    new OA\Property(property: 'active_subscriptions', type: 'integer'),
    new OA\Property(property: 'paying_customers', type: 'integer'),
    new OA\Property(property: 'mrr_movements', type: 'object', properties: [new OA\Property(property: 'new', type: 'string'), new OA\Property(property: 'expansion', type: 'string'), new OA\Property(property: 'contraction', type: 'string'), new OA\Property(property: 'churn', type: 'string'), new OA\Property(property: 'reactivation', type: 'string')]),
    new OA\Property(property: 'customer_churn_rate', type: 'string'),
    new OA\Property(property: 'revenue_churn_rate', type: 'string'),
    new OA\Property(property: 'arpa', type: 'string'),
    new OA\Property(property: 'ltv', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SubscriptionBillingForecast', properties: [
    new OA\Property(property: 'current_mrr', type: 'string'),
    new OA\Property(property: 'current_arr', type: 'string'),
    new OA\Property(property: 'forecast_months', type: 'integer'),
    new OA\Property(property: 'projected_cash_inflow', type: 'string'),
    new OA\Property(property: 'monthly_breakdown', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
])]
#[OA\Schema(schema: 'SubscriptionBillingProrationPreview', properties: [
    new OA\Property(property: 'billed_period_start', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'billed_period_end', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'remaining_days', type: 'integer'),
    new OA\Property(property: 'total_period_days', type: 'integer'),
    new OA\Property(property: 'current_period_amount', type: 'string'),
    new OA\Property(property: 'new_period_amount', type: 'string'),
    new OA\Property(property: 'net_proration', type: 'string', description: 'Positive: invoiced; negative: credited'),
])]
#[OA\Schema(schema: 'SubscriptionBillingRecognitionResult', properties: [
    new OA\Property(property: 'entries_count', type: 'integer'),
    new OA\Property(property: 'total_amount', type: 'string'),
    new OA\Property(property: 'journal_entry_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'months', type: 'array', items: new OA\Items(type: 'string', format: 'date')),
    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SubscriptionBillingPortalSubscription', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subscription_number', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'plan', type: 'string'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'next_billing_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'term_end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'auto_renew', type: 'boolean'),
    new OA\Property(property: 'invoices', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'invoice_number', type: 'string'), new OA\Property(property: 'date', type: 'string', format: 'date'), new OA\Property(property: 'total_gross', type: 'string'), new OA\Property(property: 'payment_status', type: 'string')])),
])]
#[OA\Schema(schema: 'SubscriptionBillingPaginationMeta', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])]
final class SubscriptionBillingResponseSchemas {}
