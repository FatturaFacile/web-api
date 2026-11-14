<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and records of the supplier portal endpoints.
 */
#[OA\Tag(name: 'Supplier Portal', description: 'Supplier lifecycle: onboarding workflow, required documents with expiry alerts, weighted qualification, supplier checks and risk flag, bank detail changes with callback and dual approval, comparison of the RFQ answers')]
#[OA\Tag(name: 'Supplier Portal Area', description: 'What a supplier sees and does on its own portal, authenticated by the personal token of the invitation mail: registration, documents, purchase orders, requests for quotation with quote submission, invoice status, bank detail change requests')]
#[OA\Schema(schema: 'SpInviteSupplierRequest', required: ['supplier_id', 'country'], type: 'object', properties: [
    new OA\Property(property: 'supplier_id', description: 'Supplier of the procurement module', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invitation_email', description: 'Defaults to the email of the supplier contact', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH']),
])]
#[OA\Schema(schema: 'SpOnboardingActionRequest', type: 'object', properties: [
    new OA\Property(property: 'reason', description: 'Required to reject or block', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpRegistrationRequest', required: ['legal_name', 'country', 'vat_number', 'address_line', 'postal_code', 'city', 'contact_name', 'contact_email'], type: 'object', properties: [
    new OA\Property(property: 'legal_name', type: 'string'),
    new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'vat_number', description: 'Italian partita IVA (11 digits) or Swiss UID (CHE-123.456.789), checked for format and check digit', type: 'string'),
    new OA\Property(property: 'tax_code', type: 'string', nullable: true),
    new OA\Property(property: 'address_line', type: 'string'),
    new OA\Property(property: 'postal_code', type: 'string'),
    new OA\Property(property: 'city', type: 'string'),
    new OA\Property(property: 'contact_name', type: 'string'),
    new OA\Property(property: 'contact_email', type: 'string', format: 'email'),
    new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpDocumentRequirementRequest', required: ['code', 'name'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', example: 'DURC'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'country', description: 'Empty for every country', type: 'string', enum: ['IT', 'CH'], nullable: true),
    new OA\Property(property: 'is_mandatory', type: 'boolean'),
    new OA\Property(property: 'requires_expiry', type: 'boolean'),
    new OA\Property(property: 'validity_days', description: 'Used to compute the expiry from the issue date', type: 'integer', nullable: true),
    new OA\Property(property: 'alert_days', description: 'Days before the expiry the first alert is sent', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'SpSubmitDocumentRequest', required: ['supplier_id', 'requirement_id'], type: 'object', properties: [
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'requirement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'file', description: 'PDF, JPG or PNG up to the configured size (multipart)', type: 'string', format: 'binary', nullable: true),
])]
#[OA\Schema(schema: 'SpPortalDocumentRequest', required: ['requirement_id', 'file'], type: 'object', properties: [
    new OA\Property(property: 'requirement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'file', type: 'string', format: 'binary'),
])]
#[OA\Schema(schema: 'SpAcceptDocumentRequest', type: 'object', properties: [
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_at', description: 'Required when the document type has an expiry and none was sent', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'SpRejectDocumentRequest', required: ['reason'], type: 'object', properties: [new OA\Property(property: 'reason', type: 'string')])]
#[OA\Schema(schema: 'SpQualificationCriterionRequest', required: ['code', 'name', 'weight'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'weight', type: 'number', format: 'decimal'),
    new OA\Property(property: 'max_score', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'SpQualificationScoreInput', required: ['criterion_id', 'score'], type: 'object', properties: [
    new OA\Property(property: 'criterion_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'score', type: 'number', format: 'decimal'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpQualificationAssessmentRequest', required: ['supplier_id'], type: 'object', properties: [
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'onboarding_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'scores', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpQualificationScoreInput')),
])]
#[OA\Schema(schema: 'SpRecordScoresRequest', required: ['scores'], type: 'object', properties: [
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'scores', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpQualificationScoreInput')),
])]
#[OA\Schema(schema: 'SpSupplierCheckRequest', required: ['supplier_id', 'check_type', 'result'], type: 'object', properties: [
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'check_type', type: 'string', enum: ['vies_vat', 'vat_status', 'antimafia', 'sanctions', 'business_register', 'durc_online', 'other']),
    new OA\Property(property: 'result', type: 'string', enum: ['pending', 'passed', 'failed']),
    new OA\Property(property: 'checked_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpBankChangeRequest', required: ['account_holder', 'iban'], type: 'object', properties: [
    new OA\Property(property: 'supplier_id', description: 'Required for staff, never sent from the portal', type: 'string', format: 'uuid'),
    new OA\Property(property: 'account_holder', type: 'string'),
    new OA\Property(property: 'iban', description: 'Checked with ISO 13616 (country length and mod-97)', type: 'string'),
    new OA\Property(property: 'bic', type: 'string', nullable: true),
    new OA\Property(property: 'bank_name', type: 'string', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpBankChangeDecisionRequest', type: 'object', properties: [
    new OA\Property(property: 'note', description: 'Required to record the callback', type: 'string', nullable: true),
    new OA\Property(property: 'comment', type: 'string', nullable: true),
    new OA\Property(property: 'reason', description: 'Required to reject', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpPortalQuoteLine', required: ['rfq_line_id', 'unit_price'], type: 'object', properties: [
    new OA\Property(property: 'rfq_line_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'unit_price', type: 'number', format: 'decimal'),
    new OA\Property(property: 'quantity', type: 'number', format: 'decimal', nullable: true),
    new OA\Property(property: 'tax_rate', type: 'number', format: 'decimal', nullable: true),
    new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'SpPortalQuoteRequest', required: ['lines'], type: 'object', properties: [
    new OA\Property(property: 'quote_reference', type: 'string', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpPortalQuoteLine')),
])]
#[OA\Schema(schema: 'SpOnboarding', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'supplier_vat_number', type: 'string', nullable: true),
    new OA\Property(property: 'qualification_status', description: 'Qualification status of the supplier in the procurement module', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['invited', 'registration_submitted', 'documents_required', 'under_review', 'approved', 'rejected', 'blocked']),
    new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'invitation_email', type: 'string', nullable: true),
    new OA\Property(property: 'invited_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'registration', type: 'object', nullable: true),
    new OA\Property(property: 'registration_submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'documents_submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'review_started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_notes', type: 'string', nullable: true),
    new OA\Property(property: 'status_reason', type: 'string', nullable: true),
    new OA\Property(property: 'allowed_transitions', type: 'array', items: new OA\Items(type: 'string')),
])]
#[OA\Schema(schema: 'SpOnboardingDetail', type: 'object', properties: [
    new OA\Property(property: 'onboarding', ref: '#/components/schemas/SpOnboarding'),
    new OA\Property(property: 'requirements', description: 'Active requirements of the country with the latest document of the supplier for each', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'blockers', description: 'What stops the approval; empty when it can be approved', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'assessment', ref: '#/components/schemas/SpQualificationAssessment', nullable: true),
    new OA\Property(property: 'risk', ref: '#/components/schemas/SpRiskProfile', nullable: true),
])]
#[OA\Schema(schema: 'SpDocumentRequirement', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'country', type: 'string', nullable: true),
    new OA\Property(property: 'is_mandatory', type: 'boolean'),
    new OA\Property(property: 'requires_expiry', type: 'boolean'),
    new OA\Property(property: 'validity_days', type: 'integer', nullable: true),
    new OA\Property(property: 'alert_days', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'SpDocumentSubmission', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'onboarding_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'requirement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'requirement_code', type: 'string', nullable: true),
    new OA\Property(property: 'requirement_name', type: 'string', nullable: true),
    new OA\Property(property: 'is_mandatory', type: 'boolean', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['submitted', 'accepted', 'rejected', 'superseded']),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'days_to_expiry', type: 'integer', nullable: true),
    new OA\Property(property: 'is_expired', type: 'boolean'),
    new OA\Property(property: 'has_file', type: 'boolean'),
    new OA\Property(property: 'original_name', type: 'string', nullable: true),
    new OA\Property(property: 'mime_type', type: 'string', nullable: true),
    new OA\Property(property: 'file_size', type: 'integer', nullable: true),
    new OA\Property(property: 'submitted_via', type: 'string', enum: ['internal', 'portal']),
    new OA\Property(property: 'reviewed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpQualificationCriterion', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'weight', type: 'string', example: '25.00'),
    new OA\Property(property: 'max_score', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'SpQualificationAssessment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'onboarding_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'completed']),
    new OA\Property(property: 'total_score', description: 'Weighted score from 0 to 100', type: 'string', nullable: true),
    new OA\Property(property: 'band', type: 'string', enum: ['A', 'B', 'C'], nullable: true),
    new OA\Property(property: 'outcome', type: 'string', enum: ['qualified', 'conditional', 'not_qualified'], nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'scores', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'SpSupplierCheck', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'check_type', type: 'string'),
    new OA\Property(property: 'result', type: 'string', enum: ['pending', 'passed', 'failed']),
    new OA\Property(property: 'checked_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpRiskProfile', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'level', type: 'string', enum: ['none', 'low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'score', type: 'integer'),
    new OA\Property(property: 'reasons', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'evaluated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'SpBankChange', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['internal', 'portal']),
    new OA\Property(property: 'requested_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'account_holder', type: 'string'),
    new OA\Property(property: 'iban', type: 'string'),
    new OA\Property(property: 'bic', type: 'string', nullable: true),
    new OA\Property(property: 'bank_name', type: 'string', nullable: true),
    new OA\Property(property: 'previous_iban', type: 'string', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'applied', 'rejected', 'cancelled']),
    new OA\Property(property: 'callback_verified_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'callback_verified_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'callback_note', type: 'string', nullable: true),
    new OA\Property(property: 'approvals_required', type: 'integer'),
    new OA\Property(property: 'approvals', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'applied_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SpSummary', type: 'object', properties: [
    new OA\Property(property: 'onboardings_by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
    new OA\Property(property: 'documents_to_review', type: 'integer'),
    new OA\Property(property: 'documents_expiring', description: 'Accepted documents expiring within 30 days or already expired', type: 'integer'),
    new OA\Property(property: 'bank_changes_pending', type: 'integer'),
    new OA\Property(property: 'suppliers_by_risk', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
])]
#[OA\Schema(schema: 'SpRfqComparison', type: 'object', properties: [
    new OA\Property(property: 'rfq', type: 'object'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'quotes', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'invitations', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'recommended_quote_id', description: 'Cheapest quote covering every line from a supplier that is not flagged high or critical', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'awarded_quote_id', type: 'string', format: 'uuid', nullable: true),
])]
final class SupplierPortalOpenApi {}
