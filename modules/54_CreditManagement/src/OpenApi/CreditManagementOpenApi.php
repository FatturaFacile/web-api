<?php

declare(strict_types=1);

namespace Modules\CreditManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the credit management endpoints.
 */
#[OA\Tag(name: 'Credit management', description: 'Credit risk and collections: customer scorecard and rating, credit limit requests with approval and automatic proposal, credit insurance, exposure and aging of the receivables, dunning strategies, collection cases, promises to pay, disputes, collector worklist and dashboard')]
#[OA\Response(response: 'CreditUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CreditForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CreditNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CreditUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'CreditScorecardFactor', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'source', type: 'string', enum: ['overdue_ratio', 'max_days_overdue', 'limit_utilisation', 'relationship_months', 'manual']),
    new OA\Property(property: 'weight', type: 'string', example: '1250.00'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditScorecardFactorRequest', type: 'object', required: ['code', 'name', 'source', 'weight'], properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'source', type: 'string', enum: ['overdue_ratio', 'max_days_overdue', 'limit_utilisation', 'relationship_months', 'manual']),
    new OA\Property(property: 'weight', type: 'number'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'CreditRatingBand', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'min_score', type: 'string', example: '1250.00'),
    new OA\Property(property: 'max_score', type: 'string', example: '1250.00'),
    new OA\Property(property: 'risk_level', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'limit_multiplier', type: 'string', example: '1250.00'),
    new OA\Property(property: 'limit_ceiling', type: 'string', example: '1250.00'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditRatingBandRequest', type: 'object', required: ['code', 'name', 'min_score', 'max_score', 'risk_level', 'limit_multiplier', 'limit_ceiling'], properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'min_score', type: 'number'),
    new OA\Property(property: 'max_score', type: 'number'),
    new OA\Property(property: 'risk_level', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'limit_multiplier', type: 'number'),
    new OA\Property(property: 'limit_ceiling', type: 'number'),
])]
#[OA\Schema(schema: 'CreditScorecardDefaults', type: 'object', properties: [
    new OA\Property(property: 'factors', type: 'integer'),
    new OA\Property(property: 'bands', type: 'integer'),
])]
#[OA\Schema(schema: 'CreditScore', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true),
    new OA\Property(property: 'score', type: 'string', example: '1250.00'),
    new OA\Property(property: 'rating_band_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rating', type: 'string'),
    new OA\Property(property: 'risk_level', type: 'string'),
    new OA\Property(property: 'breakdown', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'scored_on', type: 'string', format: 'date'),
    new OA\Property(property: 'scored_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditScoreRequest', type: 'object', required: ['customer_id'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'manual_inputs', type: 'object'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditLimitProposal', type: 'object', properties: [
    new OA\Property(property: 'score_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rating', type: 'string'),
    new OA\Property(property: 'score', type: 'string', example: '1250.00'),
    new OA\Property(property: 'risk_level', type: 'string'),
    new OA\Property(property: 'quarterly_turnover', type: 'string', example: '1250.00'),
    new OA\Property(property: 'proposed_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'current_limit', type: 'string', example: '1250.00'),
])]
#[OA\Schema(schema: 'CreditLimitRequest', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true),
    new OA\Property(property: 'current_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'requested_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'proposed_limit', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'approved_limit', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled']),
    new OA\Property(property: 'score_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'requested_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_note', type: 'string', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditLimitRequestRequest', type: 'object', required: ['customer_id'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'requested_limit', type: 'number', nullable: true),
    new OA\Property(property: 'currency', type: 'string', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CreditApproveLimitRequest', type: 'object', properties: [
    new OA\Property(property: 'approved_limit', type: 'number', nullable: true),
    new OA\Property(property: 'decision_note', type: 'string', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CreditRejectLimitRequest', type: 'object', required: ['decision_note'], properties: [
    new OA\Property(property: 'decision_note', type: 'string'),
])]
#[OA\Schema(schema: 'CreditInsurancePolicy', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'insurer', type: 'string'),
    new OA\Property(property: 'policy_number', type: 'string'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'coverage_percentage', type: 'string', example: '1250.00'),
    new OA\Property(property: 'max_total_cover', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'cancelled']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'granted_total', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'is_in_force', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditInsurancePolicyRequest', type: 'object', required: ['insurer', 'policy_number', 'starts_on', 'ends_on', 'currency', 'coverage_percentage'], properties: [
    new OA\Property(property: 'insurer', type: 'string'),
    new OA\Property(property: 'policy_number', type: 'string'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'coverage_percentage', type: 'number'),
    new OA\Property(property: 'max_total_cover', type: 'number', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'cancelled']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditInsuranceCover', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'policy_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true),
    new OA\Property(property: 'insured_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'status', type: 'string', enum: ['requested', 'granted', 'refused', 'withdrawn']),
    new OA\Property(property: 'granted_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditInsuranceCoverRequest', type: 'object', required: ['customer_id', 'insured_limit'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'insured_limit', type: 'number'),
    new OA\Property(property: 'status', type: 'string', enum: ['requested', 'granted', 'refused', 'withdrawn']),
    new OA\Property(property: 'granted_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditExposure', type: 'object', properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'credit_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'open_invoices', type: 'string', example: '1250.00'),
    new OA\Property(property: 'overdue_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'overdue_invoices_count', type: 'integer'),
    new OA\Property(property: 'max_days_overdue', type: 'integer'),
    new OA\Property(property: 'open_orders', type: 'string', example: '1250.00'),
    new OA\Property(property: 'exposure', type: 'string', example: '1250.00'),
    new OA\Property(property: 'available_credit', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'utilisation_percent', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'is_over_limit', type: 'boolean'),
    new OA\Property(property: 'insured_limit', type: 'string', example: '1250.00'),
    new OA\Property(property: 'uninsured_exposure', type: 'string', example: '1250.00'),
    new OA\Property(property: 'rating', type: 'string', nullable: true),
    new OA\Property(property: 'score', type: 'string', example: '1250.00', nullable: true),
])]
#[OA\Schema(schema: 'CreditAging', type: 'object', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'buckets', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'total', type: 'string', example: '1250.00'),
    new OA\Property(property: 'customers', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'CreditDunningStep', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'step_order', type: 'integer'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'days_after_due', type: 'integer'),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'letter', 'phone', 'registered_letter', 'legal']),
    new OA\Property(property: 'template_key', type: 'string', nullable: true),
    new OA\Property(property: 'fee_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'escalates', type: 'boolean'),
])]
#[OA\Schema(schema: 'CreditDunningStrategy', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_default', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditDunningStep')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditDunningStrategyRequest', type: 'object', required: ['name', 'steps'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_default', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'CreditCollectionAction', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'case_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'step_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'action_type', type: 'string', enum: ['dunning_step', 'call', 'email', 'letter', 'visit', 'legal', 'note']),
    new OA\Property(property: 'channel', type: 'string', nullable: true),
    new OA\Property(property: 'performed_on', type: 'string', format: 'date'),
    new OA\Property(property: 'performed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'fee_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'outcome', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditCollectionCase', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'case_number', type: 'string'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_dunning', 'promised', 'disputed', 'escalated', 'resolved', 'written_off']),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high']),
    new OA\Property(property: 'collector_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'strategy_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'current_step_order', type: 'integer'),
    new OA\Property(property: 'outstanding_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'opened_on', type: 'string', format: 'date'),
    new OA\Property(property: 'next_action_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'closed_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'strategy', ref: '#/components/schemas/CreditDunningStrategy', nullable: true),
    new OA\Property(property: 'invoices', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'actions', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditCollectionAction')),
    new OA\Property(property: 'promises', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditPromiseToPay')),
    new OA\Property(property: 'disputes', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditDispute')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditCollectionCaseRequest', type: 'object', required: ['customer_id'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_ids', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'strategy_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'collector_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditCollectionCaseUpdateRequest', type: 'object', properties: [
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'collector_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'next_action_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CreditAdvanceCaseRequest', type: 'object', properties: [
    new OA\Property(property: 'outcome', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditCaseActionRequest', type: 'object', required: ['action_type'], properties: [
    new OA\Property(property: 'action_type', type: 'string', enum: ['call', 'email', 'letter', 'visit', 'legal', 'note']),
    new OA\Property(property: 'channel', type: 'string', nullable: true),
    new OA\Property(property: 'performed_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'outcome', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditAssignCaseRequest', type: 'object', required: ['collector_id'], properties: [
    new OA\Property(property: 'collector_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'CreditCloseCaseRequest', type: 'object', required: ['outcome'], properties: [
    new OA\Property(property: 'outcome', type: 'string', enum: ['resolved', 'written_off']),
    new OA\Property(property: 'note', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditPromiseToPay', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'case_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'promised_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'promised_on', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'kept', 'broken', 'cancelled']),
    new OA\Property(property: 'paid_amount', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'resolved_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'recorded_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditPromiseToPayRequest', type: 'object', required: ['case_id', 'promised_amount', 'promised_on'], properties: [
    new OA\Property(property: 'case_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'promised_amount', type: 'number'),
    new OA\Property(property: 'promised_on', type: 'string', format: 'date'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditResolvePromiseRequest', type: 'object', required: ['outcome'], properties: [
    new OA\Property(property: 'outcome', type: 'string', enum: ['kept', 'broken', 'cancelled']),
    new OA\Property(property: 'paid_amount', type: 'number', nullable: true),
    new OA\Property(property: 'resolved_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CreditMarkedPromises', type: 'object', properties: [
    new OA\Property(property: 'marked', type: 'integer'),
])]
#[OA\Schema(schema: 'CreditDispute', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string', nullable: true),
    new OA\Property(property: 'case_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'disputed_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'reason_code', type: 'string', enum: ['pricing', 'quality', 'delivery', 'duplicate', 'other']),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'under_review', 'upheld', 'rejected']),
    new OA\Property(property: 'opened_on', type: 'string', format: 'date'),
    new OA\Property(property: 'resolved_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'credited_amount', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'resolution_note', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreditDisputeRequest', type: 'object', required: ['customer_id', 'disputed_amount', 'reason_code'], properties: [
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'case_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'disputed_amount', type: 'number'),
    new OA\Property(property: 'reason_code', type: 'string', enum: ['pricing', 'quality', 'delivery', 'duplicate', 'other']),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditResolveDisputeRequest', type: 'object', required: ['outcome'], properties: [
    new OA\Property(property: 'outcome', type: 'string', enum: ['upheld', 'rejected']),
    new OA\Property(property: 'credited_amount', type: 'number', nullable: true),
    new OA\Property(property: 'resolution_note', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CreditDashboard', type: 'object', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'receivables_total', type: 'string', example: '1250.00'),
    new OA\Property(property: 'overdue_total', type: 'string', example: '1250.00'),
    new OA\Property(property: 'overdue_percentage', type: 'string', example: '1250.00'),
    new OA\Property(property: 'invoiced_last_90_days', type: 'string', example: '1250.00'),
    new OA\Property(property: 'dso_days', type: 'string', example: '1250.00', nullable: true),
    new OA\Property(property: 'overdue_by_bucket', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'customers_over_limit', type: 'integer'),
    new OA\Property(property: 'cases', type: 'object'),
    new OA\Property(property: 'promises', type: 'object'),
    new OA\Property(property: 'disputes', type: 'object'),
    new OA\Property(property: 'pending_limit_requests', type: 'integer'),
    new OA\Property(property: 'rating_distribution', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'insurance', type: 'object'),
    new OA\Property(property: 'top_overdue_customers', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'CreditWorklistItem', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'case_number', type: 'string'),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'priority', type: 'string'),
    new OA\Property(property: 'collector_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'outstanding_amount', type: 'string', example: '1250.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'current_step_order', type: 'integer'),
    new OA\Property(property: 'next_action_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_action_due', type: 'boolean'),
    new OA\Property(property: 'days_overdue', type: 'integer'),
    new OA\Property(property: 'pending_promise', type: 'object', nullable: true),
])]
final class CreditManagementOpenApi {}
