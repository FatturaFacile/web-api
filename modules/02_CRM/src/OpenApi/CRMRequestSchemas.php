<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'CRMConvertLeadRequest',
    description: 'Request payload validated by ConvertLeadRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'existing_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'new_account_name', type: 'string', maxLength: 200, nullable: true),
        new OA\Property(property: 'account_tier', type: 'string', nullable: true, enum: ['strategic', 'enterprise', 'mid_market', 'smb', 'standard']),
        new OA\Property(property: 'contact_role', type: 'string', nullable: true, enum: ['decision_maker', 'influencer', 'champion', 'evaluator', 'blocker', 'economic_buyer', 'technical_buyer']),
        new OA\Property(property: 'create_opportunity', type: 'boolean', nullable: true),
        new OA\Property(property: 'opportunity_name', type: 'string', maxLength: 200, nullable: true),
        new OA\Property(property: 'opportunity_amount', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'expected_close_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreLeadRequest',
    description: 'Request payload validated by StoreLeadRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'first_name', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'company_name', type: 'string', maxLength: 200, nullable: true),
        new OA\Property(property: 'title', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 150, nullable: true),
        new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'website', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'country', type: 'string', minLength: 2, maxLength: 2, nullable: true),
        new OA\Property(property: 'lead_source_code', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'estimated_budget', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'custom_fields', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'CRMUpdateOpportunityStageRequest',
    description: 'Request payload validated by UpdateOpportunityStageRequest',
    required: ['target_stage_code'],
    type: 'object',
    properties: [
        new OA\Property(property: 'target_stage_code', type: 'string', maxLength: 50),
        new OA\Property(property: 'amount', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'probability', type: 'number', minimum: 0, maximum: 100, nullable: true),
        new OA\Property(property: 'forecast_category', type: 'string', nullable: true, enum: ['pipeline', 'best_case', 'commit', 'closed', 'omitted']),
        new OA\Property(property: 'win_loss_reason', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'competitor', type: 'string', maxLength: 150, nullable: true),
        new OA\Property(property: 'actual_close_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreOpportunityRequest',
    description: 'Request payload validated by StoreOpportunityRequest',
    required: ['account_id', 'name', 'amount'],
    type: 'object',
    properties: [
        new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'name', type: 'string', maxLength: 200),
        new OA\Property(property: 'amount', type: 'number', minimum: 0),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'stage_code', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'forecast_category', type: 'string', nullable: true, enum: ['pipeline', 'best_case', 'commit', 'closed', 'omitted']),
        new OA\Property(property: 'expected_close_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true),
        new OA\Property(property: 'line_items', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'description', type: 'string', description: 'Required with `line_items`.'), new OA\Property(property: 'quantity', type: 'number', minimum: 0.01, description: 'Required with `line_items`.'), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, description: 'Required with `line_items`.')])),
        new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreActivityRequest',
    description: 'Request payload validated by StoreActivityRequest',
    required: ['subject', 'type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'subject', type: 'string', maxLength: 200),
        new OA\Property(property: 'type', type: 'string', enum: ['task', 'call', 'meeting', 'note', 'email', 'follow_up']),
        new OA\Property(property: 'priority', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'assigned_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'related_type', type: 'string', nullable: true, enum: ['lead', 'account', 'contact', 'opportunity', 'campaign']),
        new OA\Property(property: 'related_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'duration_minutes', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'location', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'metadata', type: 'array', items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'CRMGenerateForecastRequest',
    description: 'Request payload validated by GenerateForecastRequest',
    required: ['period'],
    type: 'object',
    properties: [
        new OA\Property(property: 'period', type: 'string', maxLength: 20),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'quota_amount', type: 'number', minimum: 0, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreCampaignRequest',
    description: 'Request payload validated by StoreCampaignRequest',
    required: ['name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 200),
        new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'campaign_type_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['planned', 'active', 'completed', 'cancelled']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, description: 'Must be on or after `start_date`.'),
        new OA\Property(property: 'budgeted_cost', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'expected_revenue', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'utm_source', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'utm_medium', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'utm_campaign', type: 'string', maxLength: 100, nullable: true),
    ]
)]
final class CRMRequestSchemas {}
