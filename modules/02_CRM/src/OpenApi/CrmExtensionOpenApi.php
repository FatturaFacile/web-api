<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the CRM extension endpoints: territories and quotas, partners (PRM), CPQ and marketing
 * automation.
 */
#[OA\Tag(name: 'CRM Territories & Quotas', description: 'Sales territories with rule-based account assignment and sales quotas with attainment')]
#[OA\Tag(name: 'CRM Partners', description: 'Partner relationship management: tiers, partners, deal registration and MDF')]
#[OA\Tag(name: 'CRM CPQ', description: 'Configure, price, quote: pricing rules, discount approval thresholds and quote configurations')]
#[OA\Tag(name: 'CRM Automation', description: 'Marketing automation sequences: steps, enrolment, exit conditions and the step execution log')]
#[OA\Response(response: 'CrmExtUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CrmExtForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CrmExtNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CrmExtUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'CrmSalesTerritory', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'criteria', type: 'object', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmSalesTerritoryRequest', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'criteria', type: 'object', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'CrmAccountTerritory', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'territory_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source', type: 'string', enum: ['rule', 'manual']),
    new OA\Property(property: 'assigned_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmSalesQuota', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'territory_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'target_amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'attainment', type: 'object'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmSalesQuotaRequest', type: 'object', required: ['user_id', 'period_start', 'period_end', 'target_amount'], properties: [
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'territory_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'target_amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CrmPartnerTier', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'rank', type: 'integer'),
    new OA\Property(property: 'min_annual_revenue', type: 'string', example: '1000.00'),
    new OA\Property(property: 'discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'mdf_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmPartnerTierRequest', type: 'object', required: ['code', 'name', 'rank', 'min_annual_revenue', 'discount_percent', 'mdf_percent'], properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'rank', type: 'integer'),
    new OA\Property(property: 'min_annual_revenue', type: 'string', example: '1000.00'),
    new OA\Property(property: 'discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'mdf_percent', type: 'string', example: '1000.00'),
])]
#[OA\Schema(schema: 'CrmPartner', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'tier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'partner_type', type: 'string', enum: ['reseller', 'referral', 'distributor', 'integrator']),
    new OA\Property(property: 'status', type: 'string', enum: ['prospect', 'active', 'suspended', 'terminated']),
    new OA\Property(property: 'contact_email', type: 'string', nullable: true),
    new OA\Property(property: 'joined_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'closed_revenue', type: 'string', example: '1000.00'),
    new OA\Property(property: 'manager_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmPartnerRequest', type: 'object', required: ['name', 'partner_type', 'status'], properties: [
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'partner_type', type: 'string', enum: ['reseller', 'referral', 'distributor', 'integrator']),
    new OA\Property(property: 'status', type: 'string', enum: ['prospect', 'active', 'suspended', 'terminated']),
    new OA\Property(property: 'contact_email', type: 'string', nullable: true),
    new OA\Property(property: 'joined_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'manager_user_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'CrmDealRegistration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'opportunity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'expected_amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected', 'expired', 'won']),
    new OA\Property(property: 'conflicts_with_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'registered_on', type: 'string', format: 'date'),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'decided_by_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_reason', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmDealRegistrationRequest', type: 'object', required: ['partner_id', 'customer_name'], properties: [
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'opportunity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'expected_amount', type: 'string', example: '1000.00', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'registered_on', type: 'string', format: 'date'),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CrmMdfFund', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'fiscal_year', type: 'integer'),
    new OA\Property(property: 'allocated_amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'summary', type: 'object'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmMdfFundRequest', type: 'object', required: ['partner_id', 'fiscal_year'], properties: [
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'fiscal_year', type: 'integer'),
    new OA\Property(property: 'allocated_amount', type: 'string', example: '1000.00', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
])]
#[OA\Schema(schema: 'CrmMdfClaim', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'fund_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'activity', type: 'string'),
    new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'status', type: 'string', enum: ['submitted', 'approved', 'rejected', 'paid']),
    new OA\Property(property: 'activity_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'decided_by_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_reason', type: 'string', nullable: true),
    new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmMdfClaimRequest', type: 'object', required: ['activity', 'amount'], properties: [
    new OA\Property(property: 'activity', type: 'string'),
    new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'activity_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'CrmPricingRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'rule_type', type: 'string', enum: ['volume_discount', 'partner_discount', 'promotion']),
    new OA\Property(property: 'min_quantity', type: 'string', example: '1000.00'),
    new OA\Property(property: 'partner_tier_code', type: 'string', nullable: true),
    new OA\Property(property: 'discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmPricingRuleRequest', type: 'object', required: ['name', 'rule_type', 'min_quantity', 'discount_percent'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'rule_type', type: 'string', enum: ['volume_discount', 'partner_discount', 'promotion']),
    new OA\Property(property: 'min_quantity', type: 'string', example: '1000.00'),
    new OA\Property(property: 'partner_tier_code', type: 'string', nullable: true),
    new OA\Property(property: 'discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'CrmDiscountThreshold', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'from_discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'approver_role', type: 'string', enum: ['sales_manager', 'admin', 'owner']),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmDiscountThresholdRequest', type: 'object', required: ['from_discount_percent', 'approver_role'], properties: [
    new OA\Property(property: 'from_discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'approver_role', type: 'string', enum: ['sales_manager', 'admin', 'owner']),
])]
#[OA\Schema(schema: 'CrmQuoteConfiguration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'opportunity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'product_configuration_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string', example: '1000.00'),
    new OA\Property(property: 'unit_price', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'rule_discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'extra_discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'discount_percent', type: 'string', example: '1000.00'),
    new OA\Property(property: 'net_total', type: 'string', example: '1000.00'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected']),
    new OA\Property(property: 'required_approver_role', type: 'string', nullable: true),
    new OA\Property(property: 'decided_by_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_reason', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmQuoteConfigurationRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'opportunity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'product_configuration_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'partner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string', example: '1000.00'),
    new OA\Property(property: 'unit_price', type: 'string', example: '1000.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'extra_discount_percent', type: 'string', example: '1000.00'),
])]
#[OA\Schema(schema: 'CrmAutomationSequence', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'entity', type: 'string', enum: ['contacts', 'leads']),
    new OA\Property(property: 'trigger_type', type: 'string', enum: ['manual', 'lead_created', 'lead_qualified', 'contact_created']),
    new OA\Property(property: 'segment_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'exit_conditions', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmAutomationSequenceRequest', type: 'object', required: ['name', 'entity', 'trigger_type'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'entity', type: 'string', enum: ['contacts', 'leads']),
    new OA\Property(property: 'trigger_type', type: 'string', enum: ['manual', 'lead_created', 'lead_qualified', 'contact_created']),
    new OA\Property(property: 'segment_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'exit_conditions', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string')),
])]
#[OA\Schema(schema: 'CrmAutomationEnrollment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sequence_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'completed', 'exited']),
    new OA\Property(property: 'current_position', type: 'integer'),
    new OA\Property(property: 'next_run_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'exit_reason', type: 'string', nullable: true),
    new OA\Property(property: 'enrolled_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmAutomationEnrollmentRequest', type: 'object', properties: [
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'CrmAutomationStepLog', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'enrollment_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'step_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'step_type', type: 'string', enum: ['email', 'task', 'wait']),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'message', type: 'string', nullable: true),
    new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CrmDecisionReason', type: 'object', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])]
final class CrmExtensionOpenApi {}
