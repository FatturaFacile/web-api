<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'CRMLeadResource',
    description: 'JSON representation produced by LeadResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'first_name'),
        new OA\Property(property: 'last_name'),
        new OA\Property(property: 'full_name'),
        new OA\Property(property: 'company_name'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'email', type: 'string'),
        new OA\Property(property: 'phone', type: 'string'),
        new OA\Property(property: 'website'),
        new OA\Property(property: 'country', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'score', type: 'integer', nullable: true),
        new OA\Property(property: 'rating'),
        new OA\Property(property: 'score_breakdown', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadScoreItem')),
        new OA\Property(property: 'score_model', type: 'string', nullable: true, enum: ['default', 'tenant_rules']),
        new OA\Property(property: 'score_calculated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'estimated_budget', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'lead_source', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')]),
        new OA\Property(property: 'converted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'converted_account_id', type: 'string'),
        new OA\Property(property: 'converted_contact_id', type: 'string'),
        new OA\Property(property: 'converted_opportunity_id', type: 'string'),
        new OA\Property(property: 'tags'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMAccountResource',
    description: 'JSON representation produced by AccountResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'account_number'),
        new OA\Property(property: 'name', type: 'string', description: 'Company name of the linked contact (the account copy when unlinked)'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'contact', ref: '#/components/schemas/CRMContactMasterData', nullable: true),
        new OA\Property(property: 'parent_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'industry'),
        new OA\Property(property: 'tier'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'annual_revenue', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'employee_count', type: 'integer', nullable: true),
        new OA\Property(property: 'website'),
        new OA\Property(property: 'customer_health_index', type: 'number'),
        new OA\Property(property: 'churn_risk'),
        new OA\Property(property: 'lifecycle_stage'),
        new OA\Property(property: 'last_activity_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'tags'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMOpportunityResource',
    description: 'JSON representation produced by OpportunityResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'opportunity_number'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'expected_revenue', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'probability', type: 'number'),
        new OA\Property(property: 'forecast_category'),
        new OA\Property(property: 'expected_close_date', type: 'string', format: 'date'),
        new OA\Property(property: 'actual_close_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'win_loss_reason'),
        new OA\Property(property: 'competitor'),
        new OA\Property(property: 'discount_percentage', type: 'number'),
        new OA\Property(property: 'approval_status'),
        new OA\Property(property: 'workflow_instance_id', type: 'string'),
        new OA\Property(property: 'account', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'tier')]),
        new OA\Property(property: 'stage', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'is_won', type: 'boolean'), new OA\Property(property: 'is_lost', type: 'boolean')]),
        new OA\Property(property: 'sales_quote_id', type: 'string'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMCustomerHealthProfileResource',
    description: 'JSON representation produced by CustomerHealthProfileResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'account_id', type: 'string'),
        new OA\Property(property: 'activity_score', type: 'number'),
        new OA\Property(property: 'engagement_score', type: 'number'),
        new OA\Property(property: 'revenue_score', type: 'number'),
        new OA\Property(property: 'risk_score', type: 'number'),
        new OA\Property(property: 'churn_risk_indicator', type: 'string', enum: ['low', 'medium', 'high', 'critical'], description: 'Derived from the health index and the risk score'),
        new OA\Property(property: 'health_index', type: 'number'),
        new OA\Property(property: 'last_interaction_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'open_receivable_amount', type: 'number', description: 'JSON number (this resource does not use decimal strings)'),
        new OA\Property(property: 'overdue_receivable_amount', type: 'number'),
        new OA\Property(property: 'evaluation_metrics', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'evaluated_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'completed_activities', type: 'integer'),
            new OA\Property(property: 'days_since_interaction', type: 'integer', nullable: true),
        ]),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMActivityResource',
    description: 'JSON representation produced by ActivityResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'subject'),
        new OA\Property(property: 'type'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'assigned_user_id', type: 'string'),
        new OA\Property(property: 'created_by_user_id', type: 'string', nullable: true),
        new OA\Property(property: 'reminder_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reminder_sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'related_type'),
        new OA\Property(property: 'related_id', type: 'string'),
        new OA\Property(property: 'duration_minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'location'),
        new OA\Property(property: 'outcome'),
        new OA\Property(property: 'notes', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMForecastResource',
    description: 'JSON representation produced by ForecastResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period'),
        new OA\Property(property: 'user_id', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'pipeline_amount', type: 'number'),
        new OA\Property(property: 'best_case_amount', type: 'number'),
        new OA\Property(property: 'commit_amount', type: 'number'),
        new OA\Property(property: 'closed_amount', type: 'number'),
        new OA\Property(property: 'weighted_amount', type: 'number'),
        new OA\Property(property: 'quota_amount', type: 'number'),
        new OA\Property(property: 'attainment_percent', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'workflow_instance_id', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMCampaignResource',
    description: 'JSON representation produced by CampaignResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'budgeted_cost', type: 'number'),
        new OA\Property(property: 'actual_cost', type: 'number'),
        new OA\Property(property: 'expected_revenue', type: 'number'),
        new OA\Property(property: 'actual_revenue', type: 'number'),
        new OA\Property(property: 'roi_percentage'),
        new OA\Property(property: 'utm_source'),
        new OA\Property(property: 'utm_medium'),
        new OA\Property(property: 'utm_campaign'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class CRMResponseSchemas {}
