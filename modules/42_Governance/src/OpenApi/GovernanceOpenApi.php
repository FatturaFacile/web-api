<?php

declare(strict_types=1);

namespace Modules\Governance\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the governance, risk and compliance endpoints.
 */
#[OA\Tag(name: 'Governance', description: 'Governance, risk and compliance: risk register with 1-5 likelihood x impact scoring, controls library with control tests and DMS evidence, annual internal audit plan, audits, findings with remediation actions, compliance obligations calendar (D.Lgs. 231/2001, GDPR, NIS2, Swiss FADP, ISO 27001) and dashboard')]
#[OA\Response(response: 'GovernanceUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'GovernanceForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'GovernanceNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'GovernanceUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'GovTransitionRequest', required: ['status'], type: 'object', properties: [
    new OA\Property(property: 'status', description: 'Target status; the allowed targets depend on the current status of the record', type: 'string', maxLength: 30),
    new OA\Property(property: 'notes', description: 'Justification, closure notes, conclusion or reason, required by some transitions', type: 'string', maxLength: 4000, nullable: true),
])]
#[OA\Schema(schema: 'GovRiskRequest', required: ['title', 'category', 'likelihood', 'impact'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'category', type: 'string', enum: ['strategic', 'operational', 'financial', 'compliance', 'it_security', 'legal', 'reputational', 'environmental']),
    new OA\Property(property: 'likelihood', type: 'integer', minimum: 1, maximum: 5),
    new OA\Property(property: 'impact', type: 'integer', minimum: 1, maximum: 5),
    new OA\Property(property: 'residual_likelihood', description: 'Both residual values together; neither can exceed the inherent value', type: 'integer', minimum: 1, maximum: 5, nullable: true),
    new OA\Property(property: 'residual_impact', type: 'integer', minimum: 1, maximum: 5, nullable: true),
    new OA\Property(property: 'treatment', type: 'string', enum: ['mitigate', 'accept', 'transfer', 'avoid'], nullable: true),
    new OA\Property(property: 'treatment_notes', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', description: 'Member of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'next_review_date', type: 'string', format: 'date', nullable: true, description: 'Defaults to 3, 6 or 12 months by rating'),
])]
#[OA\Schema(schema: 'GovRiskControlsRequest', required: ['control_ids'], type: 'object', properties: [
    new OA\Property(property: 'control_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
])]
#[OA\Schema(schema: 'GovRisk', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'RSK-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'category', type: 'string', enum: ['strategic', 'operational', 'financial', 'compliance', 'it_security', 'legal', 'reputational', 'environmental']),
    new OA\Property(property: 'likelihood', type: 'integer'),
    new OA\Property(property: 'impact', type: 'integer'),
    new OA\Property(property: 'inherent_score', type: 'integer', minimum: 1, maximum: 25),
    new OA\Property(property: 'inherent_rating', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'residual_likelihood', type: 'integer', nullable: true),
    new OA\Property(property: 'residual_impact', type: 'integer', nullable: true),
    new OA\Property(property: 'residual_score', type: 'integer', nullable: true),
    new OA\Property(property: 'residual_rating', type: 'string', enum: ['low', 'medium', 'high', 'critical'], nullable: true),
    new OA\Property(property: 'treatment', type: 'string', enum: ['mitigate', 'accept', 'transfer', 'avoid'], nullable: true),
    new OA\Property(property: 'treatment_notes', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['identified', 'assessed', 'treating', 'accepted', 'closed']),
    new OA\Property(property: 'next_review_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_review_overdue', type: 'boolean'),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'controls', description: 'Only on the single record', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovControl')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovMemberReference', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
])]
#[OA\Schema(schema: 'GovMember', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'role', type: 'string'),
])]
#[OA\Schema(schema: 'GovControlRequest', required: ['title', 'control_type', 'frequency'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'control_type', type: 'string', enum: ['preventive', 'detective', 'corrective']),
    new OA\Property(property: 'frequency', type: 'string', enum: ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual', 'ad_hoc']),
    new OA\Property(property: 'framework_reference', description: 'Clause or article the control implements, for example ISO/IEC 27001 A.5.15', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', description: 'draft, active or retired; the change follows the state machine', type: 'string', enum: ['draft', 'active', 'retired']),
])]
#[OA\Schema(schema: 'GovControl', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'CTL-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'control_type', type: 'string', enum: ['preventive', 'detective', 'corrective']),
    new OA\Property(property: 'frequency', type: 'string', enum: ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual', 'ad_hoc']),
    new OA\Property(property: 'framework_reference', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'retired']),
    new OA\Property(property: 'last_tested_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'last_test_result', type: 'string', enum: ['pass', 'partial', 'fail'], nullable: true),
    new OA\Property(property: 'next_test_due_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_test_overdue', type: 'boolean'),
    new OA\Property(property: 'risk_ids', description: 'Only on the single record', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovControlTestRequest', required: ['tested_on', 'result'], type: 'object', properties: [
    new OA\Property(property: 'tested_on', type: 'string', format: 'date', description: 'Not in the future'),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'partial', 'fail']),
    new OA\Property(property: 'sample_size', type: 'integer', minimum: 1, nullable: true),
    new OA\Property(property: 'exceptions_found', type: 'integer', minimum: 0, nullable: true, description: 'At most the sample size'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'evidence_document_ids', description: 'Documents of the DMS of the company', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    new OA\Property(property: 'raise_finding', description: 'Open a finding for a partial or failed result', type: 'boolean'),
])]
#[OA\Schema(schema: 'GovControlTest', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'control_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tested_on', type: 'string', format: 'date'),
    new OA\Property(property: 'tester_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'tester', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'partial', 'fail']),
    new OA\Property(property: 'sample_size', type: 'integer', nullable: true),
    new OA\Property(property: 'exceptions_found', type: 'integer', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'evidence_document_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'finding_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovAuditPlanRequest', required: ['year', 'title'], type: 'object', properties: [
    new OA\Property(property: 'year', description: 'One plan per year', type: 'integer', minimum: 2000, maximum: 2100),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'GovAuditPlan', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'closed']),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'audits_count', type: 'integer'),
    new OA\Property(property: 'audits', description: 'Only on the single record', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovAudit')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovAuditRequest', required: ['title', 'audit_type', 'scope', 'lead_auditor_id', 'planned_start', 'planned_end'], type: 'object', properties: [
    new OA\Property(property: 'audit_plan_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'audit_type', type: 'string', enum: ['process', 'compliance', 'it', 'financial', 'follow_up']),
    new OA\Property(property: 'scope', type: 'string'),
    new OA\Property(property: 'objectives', type: 'string', nullable: true),
    new OA\Property(property: 'lead_auditor_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'team_member_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    new OA\Property(property: 'planned_start', type: 'string', format: 'date'),
    new OA\Property(property: 'planned_end', type: 'string', format: 'date'),
    new OA\Property(property: 'conclusion', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'GovAudit', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'audit_plan_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'code', type: 'string', example: 'AUD-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'audit_type', type: 'string', enum: ['process', 'compliance', 'it', 'financial', 'follow_up']),
    new OA\Property(property: 'scope', type: 'string'),
    new OA\Property(property: 'objectives', type: 'string', nullable: true),
    new OA\Property(property: 'lead_auditor_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lead_auditor', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'team_member_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'planned_start', type: 'string', format: 'date'),
    new OA\Property(property: 'planned_end', type: 'string', format: 'date'),
    new OA\Property(property: 'actual_start', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'actual_end', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['planned', 'in_progress', 'reporting', 'closed', 'cancelled']),
    new OA\Property(property: 'conclusion', type: 'string', nullable: true),
    new OA\Property(property: 'is_late_to_start', type: 'boolean'),
    new OA\Property(property: 'findings_count', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovFindingRequest', required: ['source', 'title', 'severity'], type: 'object', properties: [
    new OA\Property(property: 'source', type: 'string', enum: ['audit', 'control_test', 'self_assessment', 'external']),
    new OA\Property(property: 'audit_id', description: 'Required for the source audit; the audit must be in progress or reporting', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'control_id', description: 'Required for the source control_test', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'risk_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'severity', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'recommendation', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'GovFinding', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'FND-2027-0001'),
    new OA\Property(property: 'audit_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'control_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'risk_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['audit', 'control_test', 'self_assessment', 'external']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'severity', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'recommendation', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'remediation', 'resolved', 'closed', 'risk_accepted']),
    new OA\Property(property: 'accepted_reason', type: 'string', nullable: true),
    new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'accepted_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'closure_notes', type: 'string', nullable: true),
    new OA\Property(property: 'actions_count', type: 'integer'),
    new OA\Property(property: 'actions', description: 'Only on the single record', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovAction')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovActionRequest', required: ['title', 'owner_id', 'due_date'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'due_date', description: 'Today or later, within the term of the severity of the finding (critical 30 days, high 90, medium 180, low 365)', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'GovAction', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'finding_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'ACT-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'owner', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'completed', 'verified', 'cancelled']),
    new OA\Property(property: 'is_overdue', type: 'boolean'),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'completion_notes', type: 'string', nullable: true),
    new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'verified_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'verification_notes', type: 'string', nullable: true),
    new OA\Property(property: 'finding', description: 'Code, title and severity of the finding, on the list and the single record', type: 'object', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovObligationRequest', required: ['framework', 'jurisdiction', 'title', 'recurrence', 'due_date'], type: 'object', properties: [
    new OA\Property(property: 'framework', type: 'string', enum: ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001', 'other']),
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH', 'EU', 'INT']),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'legal_reference', type: 'string', nullable: true),
    new OA\Property(property: 'recurrence', type: 'string', enum: ['once', 'monthly', 'quarterly', 'semiannual', 'annual']),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'reminder_days', type: 'integer', minimum: 0, maximum: 365, nullable: true, description: 'Defaults to 30'),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'evidence_document_id', description: 'Document of the DMS of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'GovObligationCompleteRequest', type: 'object', properties: [
    new OA\Property(property: 'completed_on', type: 'string', format: 'date', nullable: true, description: 'Defaults to today, never in the future'),
    new OA\Property(property: 'evidence_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'GovSeedObligationTemplatesRequest', required: ['frameworks'], type: 'object', properties: [
    new OA\Property(property: 'frameworks', type: 'array', items: new OA\Items(type: 'string', enum: ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001'])),
    new OA\Property(property: 'first_due_date', type: 'string', format: 'date', nullable: true, description: 'Today or later; defaults to 30 days from today'),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'GovObligation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'template_key', type: 'string', nullable: true),
    new OA\Property(property: 'framework', type: 'string', enum: ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001', 'other']),
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH', 'EU', 'INT']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'legal_reference', type: 'string', nullable: true),
    new OA\Property(property: 'recurrence', type: 'string', enum: ['once', 'monthly', 'quarterly', 'semiannual', 'annual']),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'reminder_days', type: 'integer'),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled']),
    new OA\Property(property: 'is_overdue', type: 'boolean'),
    new OA\Property(property: 'is_due_soon', type: 'boolean'),
    new OA\Property(property: 'last_completed_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'last_completed_by', ref: '#/components/schemas/GovMemberReference', nullable: true),
    new OA\Property(property: 'evidence_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovObligationTemplate', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string', example: 'gdpr.ropa_review'),
    new OA\Property(property: 'framework', type: 'string', enum: ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001']),
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH', 'EU', 'INT']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'legal_reference', type: 'string'),
    new OA\Property(property: 'recurrence', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual']),
    new OA\Property(property: 'reminder_days', type: 'integer'),
    new OA\Property(property: 'already_seeded', type: 'boolean'),
])]
#[OA\Schema(schema: 'GovDashboard', type: 'object', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'basis', type: 'string', enum: ['residual', 'inherent']),
    new OA\Property(property: 'risks', type: 'object', properties: [
        new OA\Property(property: 'total', description: 'Risks still managed (not closed)', type: 'integer'),
        new OA\Property(property: 'by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'by_rating', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'review_overdue', type: 'integer'),
        new OA\Property(property: 'heatmap', description: '25 cells, likelihood 5 to 1, impact 1 to 5', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'likelihood', type: 'integer'),
            new OA\Property(property: 'impact', type: 'integer'),
            new OA\Property(property: 'score', type: 'integer'),
            new OA\Property(property: 'rating', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
            new OA\Property(property: 'count', type: 'integer'),
        ], type: 'object')),
    ]),
    new OA\Property(property: 'controls', type: 'object', properties: [
        new OA\Property(property: 'active', type: 'integer'),
        new OA\Property(property: 'test_overdue', type: 'integer'),
        new OA\Property(property: 'never_tested', type: 'integer'),
        new OA\Property(property: 'tests_in_period', type: 'integer'),
        new OA\Property(property: 'tests_by_result', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'pass_rate', description: 'Percent of passed tests in the period, null without tests', type: 'number', nullable: true),
        new OA\Property(property: 'pass_rate_months', type: 'integer'),
    ]),
    new OA\Property(property: 'findings', type: 'object', properties: [
        new OA\Property(property: 'open_total', type: 'integer'),
        new OA\Property(property: 'open_by_severity', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'risk_accepted', type: 'integer'),
    ]),
    new OA\Property(property: 'actions', type: 'object', properties: [
        new OA\Property(property: 'open', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'due_next_30_days', type: 'integer'),
        new OA\Property(property: 'awaiting_verification', type: 'integer'),
    ]),
    new OA\Property(property: 'obligations', type: 'object', properties: [
        new OA\Property(property: 'open', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'due_next_30_days', type: 'integer'),
    ]),
    new OA\Property(property: 'audits', type: 'object', properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'plan_status', type: 'string', nullable: true),
        new OA\Property(property: 'plan_audits', type: 'integer'),
        new OA\Property(property: 'plan_closed', type: 'integer'),
        new OA\Property(property: 'plan_completion_rate', type: 'number', nullable: true),
        new OA\Property(property: 'in_progress', type: 'integer'),
        new OA\Property(property: 'late_to_start', type: 'integer'),
    ]),
])]
final class GovernanceOpenApi {}
