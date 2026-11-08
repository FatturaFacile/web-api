<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas of the party-master consolidation and of the enterprise CRM features (lead scoring, pipeline stages,
 * duplicates and merges, segments, campaign audience, GDPR consents, reports). Kept apart from the operation
 * classes because a class carrying class-level parameters would drop its schemas.
 */
#[OA\Schema(
    schema: 'CRMContactMasterData',
    description: 'Master data of the linked contact (the party master shared with invoices, quotes and orders).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true),
        new OA\Property(property: 'first_name', type: 'string', nullable: true),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'display_name', type: 'string'),
        new OA\Property(property: 'vat_number', type: 'string', nullable: true),
        new OA\Property(property: 'fiscal_code', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'pec', type: 'string', nullable: true),
        new OA\Property(property: 'sdi_code', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreAccountRequest',
    description: 'A CRM account. Give contact_id to link an existing contact, or the master data to match (VAT number, fiscal code, email) or create one.',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 200, description: 'Required without contact_id'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tier', type: 'string', nullable: true, enum: ['strategic', 'enterprise', 'mid_market', 'smb', 'standard']),
        new OA\Property(property: 'industry', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'website', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'vat_number', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'fiscal_code', type: 'string', maxLength: 16, nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'pec', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'sdi_code', type: 'string', maxLength: 7, nullable: true),
        new OA\Property(property: 'country', type: 'string', minLength: 2, maxLength: 2, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMUpdateAccountRequest',
    description: 'Partial update of an account. Master data fields are written to the linked contact.',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 200),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tier', type: 'string', nullable: true, enum: ['strategic', 'enterprise', 'mid_market', 'smb', 'standard']),
        new OA\Property(property: 'industry', type: 'string', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['prospect', 'active', 'inactive', 'churned']),
        new OA\Property(property: 'annual_revenue', type: 'number', nullable: true),
        new OA\Property(property: 'employee_count', type: 'integer', nullable: true),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'vat_number', type: 'string', nullable: true),
        new OA\Property(property: 'fiscal_code', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'pec', type: 'string', nullable: true),
        new OA\Property(property: 'sdi_code', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMPartyLinkLog',
    description: 'Decision of the account-to-contact consolidation.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'outcome', type: 'string', enum: ['linked', 'created', 'ambiguous', 'resolved']),
        new OA\Property(property: 'matched_on', type: 'string', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'candidate_contact_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMLeadScoringRule',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'rule_type', type: 'string', enum: ['field', 'activity']),
        new OA\Property(property: 'field', type: 'string', nullable: true),
        new OA\Property(property: 'operator', type: 'string', nullable: true),
        new OA\Property(property: 'value', type: 'string', nullable: true),
        new OA\Property(property: 'activity_type', type: 'string', nullable: true),
        new OA\Property(property: 'activity_status', type: 'string', nullable: true),
        new OA\Property(property: 'points', type: 'integer'),
        new OA\Property(property: 'max_points', type: 'integer', nullable: true),
        new OA\Property(property: 'sequence', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'CRMSaveLeadScoringRuleRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'rule_type', type: 'string', enum: ['field', 'activity']),
        new OA\Property(property: 'field', type: 'string', nullable: true, enum: ['title', 'email', 'phone', 'company_name', 'website', 'country', 'estimated_budget', 'status', 'lead_source_code', 'campaign_id']),
        new OA\Property(property: 'operator', type: 'string', nullable: true, enum: ['filled', 'empty', 'equals', 'not_equals', 'contains', 'in', 'gte', 'lte', 'business_email']),
        new OA\Property(property: 'value', type: 'string', nullable: true),
        new OA\Property(property: 'activity_type', type: 'string', nullable: true, enum: ['task', 'call', 'meeting', 'note', 'email', 'follow_up']),
        new OA\Property(property: 'activity_status', type: 'string', nullable: true, enum: ['pending', 'in_progress', 'completed', 'cancelled']),
        new OA\Property(property: 'points', type: 'integer', minimum: -100, maximum: 100),
        new OA\Property(property: 'max_points', type: 'integer', nullable: true),
        new OA\Property(property: 'sequence', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMLeadScoreItem',
    type: 'object',
    properties: [
        new OA\Property(property: 'rule', type: 'string'),
        new OA\Property(property: 'points', type: 'integer'),
        new OA\Property(property: 'reason', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'CRMLeadScoreExplanation',
    type: 'object',
    properties: [
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'score', type: 'integer'),
        new OA\Property(property: 'rating', type: 'string', enum: ['hot', 'warm', 'cold']),
        new OA\Property(property: 'model', type: 'string', nullable: true, enum: ['default', 'tenant_rules']),
        new OA\Property(property: 'breakdown', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadScoreItem')),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'current', type: 'object', properties: [
            new OA\Property(property: 'score', type: 'integer'),
            new OA\Property(property: 'rating', type: 'string'),
            new OA\Property(property: 'model', type: 'string'),
            new OA\Property(property: 'breakdown', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadScoreItem')),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'CRMPipelineStage',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sequence', type: 'integer'),
        new OA\Property(property: 'default_probability', type: 'number'),
        new OA\Property(property: 'is_won', type: 'boolean'),
        new OA\Property(property: 'is_lost', type: 'boolean'),
        new OA\Property(property: 'stale_days', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'CRMSavePipelineStageRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Creation only: lowercase letters, digits, underscore'),
        new OA\Property(property: 'name', type: 'string', maxLength: 100),
        new OA\Property(property: 'sequence', type: 'integer', nullable: true),
        new OA\Property(property: 'default_probability', type: 'number', minimum: 0, maximum: 100),
        new OA\Property(property: 'is_won', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_lost', type: 'boolean', nullable: true),
        new OA\Property(property: 'stale_days', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'reprice_open_opportunities', type: 'boolean', nullable: true, description: 'Update only: apply the new probability to the open opportunities of the stage'),
    ]
)]
#[OA\Schema(
    schema: 'CRMDuplicateGroup',
    description: 'Records sharing normalised identifying keys.',
    type: 'object',
    properties: [
        new OA\Property(property: 'matched_on', type: 'array', items: new OA\Items(type: 'string', enum: ['vat_number', 'fiscal_code', 'email', 'phone'])),
        new OA\Property(property: 'records', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'CRMMergeContactsRequest',
    type: 'object',
    required: ['survivor_id', 'merged_id'],
    properties: [
        new OA\Property(property: 'survivor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'merged_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fill_missing', type: 'boolean', default: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMContactMerge',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'survivor_contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'merged_contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'repointed', type: 'object', description: 'Rows repointed per table.column', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'retained', type: 'object', description: 'Immutable rows that keep the merged contact, per table.column', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'filled_fields', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'merged_snapshot', type: 'object'),
        new OA\Property(property: 'merged_by_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMSegmentFilter',
    type: 'object',
    properties: [
        new OA\Property(property: 'field', type: 'string', description: 'A whitelisted column, or "consent"'),
        new OA\Property(property: 'operator', type: 'string', enum: ['eq', 'neq', 'contains', 'starts_with', 'in', 'gte', 'lte', 'is_null', 'not_null']),
        new OA\Property(property: 'value', description: 'Compared value (string, number or list for "in")'),
        new OA\Property(property: 'channel', type: 'string', description: 'Consent filter only', enum: ['email', 'sms', 'phone', 'post']),
        new OA\Property(property: 'purpose', type: 'string', description: 'Consent filter only', enum: ['marketing', 'newsletter', 'profiling']),
    ]
)]
#[OA\Schema(
    schema: 'CRMSegment',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'entity', type: 'string', enum: ['contacts', 'leads']),
        new OA\Property(property: 'filters', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMSegmentFilter')),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'member_count', type: 'integer', description: 'Only on the single segment endpoint'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMSaveSegmentRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'entity', type: 'string', enum: ['contacts', 'leads'], description: 'Creation only'),
        new OA\Property(property: 'filters', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMSegmentFilter')),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMCampaignMember',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['planned', 'sent', 'suppressed', 'responded', 'attended', 'converted', 'bounced']),
        new OA\Property(property: 'suppression_reason', type: 'string', nullable: true, enum: ['opted_out', 'no_consent', 'no_email']),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'first_associated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreCampaignMemberRequest',
    description: 'Exactly one of contact_id, lead_id, segment_id.',
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'segment_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMSendCampaignRequest',
    type: 'object',
    required: ['subject', 'body'],
    properties: [
        new OA\Property(property: 'subject', type: 'string', maxLength: 200),
        new OA\Property(property: 'body', type: 'string', maxLength: 20000),
    ]
)]
#[OA\Schema(
    schema: 'CRMCampaignSendResult',
    type: 'object',
    properties: [
        new OA\Property(property: 'sent', type: 'integer'),
        new OA\Property(property: 'suppressed', type: 'integer'),
        new OA\Property(property: 'skipped', type: 'integer', description: 'Members already contacted earlier'),
        new OA\Property(property: 'suppression_reasons', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
    ]
)]
#[OA\Schema(
    schema: 'CRMConsent',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'sms', 'phone', 'post']),
        new OA\Property(property: 'purpose', type: 'string', enum: ['marketing', 'newsletter', 'profiling']),
        new OA\Property(property: 'status', type: 'string', enum: ['granted', 'withdrawn']),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'evidence', type: 'string', nullable: true),
        new OA\Property(property: 'recorded_by_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CRMConsentState',
    type: 'object',
    properties: [
        new OA\Property(property: 'channel', type: 'string'),
        new OA\Property(property: 'purpose', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['granted', 'withdrawn']),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'source', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'CRMStoreConsentRequest',
    description: 'Exactly one of contact_id or lead_id.',
    type: 'object',
    required: ['channel', 'purpose', 'status', 'source'],
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'sms', 'phone', 'post']),
        new OA\Property(property: 'purpose', type: 'string', enum: ['marketing', 'newsletter', 'profiling']),
        new OA\Property(property: 'status', type: 'string', enum: ['granted', 'withdrawn']),
        new OA\Property(property: 'source', type: 'string', enum: ['web_form', 'import', 'phone', 'in_person', 'api', 'unsubscribe_link', 'lead_conversion', 'contact_merge']),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'evidence', type: 'string', maxLength: 2000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CRMUpdateActivityRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'subject', type: 'string', maxLength: 200),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'due_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reminder_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'assigned_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'duration_minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'cancelled']),
    ]
)]
#[OA\Schema(
    schema: 'CRMPipelineReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'by_stage', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'stage_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'probability', type: 'number'),
            new OA\Property(property: 'opportunities', type: 'integer'),
            new OA\Property(property: 'amount', type: 'number'),
            new OA\Property(property: 'weighted_amount', type: 'number'),
        ])),
        new OA\Property(property: 'by_owner', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'opportunities', type: 'integer'),
            new OA\Property(property: 'amount', type: 'number'),
            new OA\Property(property: 'weighted_amount', type: 'number'),
        ])),
        new OA\Property(property: 'totals', type: 'object', properties: [
            new OA\Property(property: 'opportunities', type: 'integer'),
            new OA\Property(property: 'amount', type: 'number'),
            new OA\Property(property: 'weighted_amount', type: 'number'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'CRMConversionReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'leads', type: 'object', properties: [
            new OA\Property(property: 'total', type: 'integer'),
            new OA\Property(property: 'by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
            new OA\Property(property: 'qualified_or_converted', type: 'integer'),
            new OA\Property(property: 'converted', type: 'integer'),
            new OA\Property(property: 'qualification_rate', type: 'number'),
            new OA\Property(property: 'conversion_rate', type: 'number'),
            new OA\Property(property: 'average_days_to_convert', type: 'number', nullable: true),
            new OA\Property(property: 'by_source', type: 'array', items: new OA\Items(type: 'object')),
        ]),
        new OA\Property(property: 'opportunities', type: 'object', properties: [
            new OA\Property(property: 'won', type: 'integer'),
            new OA\Property(property: 'lost', type: 'integer'),
            new OA\Property(property: 'won_amount', type: 'number'),
            new OA\Property(property: 'lost_amount', type: 'number'),
            new OA\Property(property: 'win_rate', type: 'number'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'CRMActivityReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'completed', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'completion_rate', type: 'number'),
        new OA\Property(property: 'by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'by_type', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'by_assignee', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
final class CRMEngagementOpenApiComponents {}
