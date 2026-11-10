<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of the GDPR (EU 2016/679) and Swiss nLPD (RS 235.1) compliance endpoints: record of
 * processing activities, subprocessors, data subject requests, portability dossier, personal data breaches and the
 * log of accesses to sensitive personal data. Fields marked as personal data must be shown only to authorised users.
 */
#[OA\Schema(schema: 'PrivacyProcessingActivityRequest', description: 'Entry of the record of processing activities (Art. 30 GDPR / Art. 12 nLPD). Validated inline by PrivacyComplianceController::storeProcessingActivity.', required: ['title', 'purpose', 'legal_basis', 'data_categories', 'subject_categories', 'retention_period'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190, example: 'Customer invoicing'),
    new OA\Property(property: 'purpose', type: 'string', maxLength: 2000, example: 'Issue and archive invoices'),
    new OA\Property(property: 'legal_basis', type: 'string', maxLength: 60, example: 'contract'),
    new OA\Property(property: 'data_categories', type: 'array', minItems: 1, items: new OA\Items(type: 'string', maxLength: 80), example: ['identification', 'billing']),
    new OA\Property(property: 'subject_categories', type: 'array', minItems: 1, items: new OA\Items(type: 'string', maxLength: 80), example: ['customers']),
    new OA\Property(property: 'recipients', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['accounting firm']),
    new OA\Property(property: 'cross_border_countries', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['CH']),
    new OA\Property(property: 'transfer_safeguards', type: 'string', maxLength: 80, nullable: true),
    new OA\Property(property: 'retention_period', type: 'string', maxLength: 120, example: '10 years'),
    new OA\Property(property: 'security_measures', type: 'string', maxLength: 2000, nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived'], nullable: true, description: 'Defaults to active'),
])]
#[OA\Schema(schema: 'PrivacyProcessingActivity', description: 'Entry of the record of processing activities of the company', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'purpose', type: 'string'),
    new OA\Property(property: 'legal_basis', type: 'string'),
    new OA\Property(property: 'data_categories', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'subject_categories', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'recipients', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
    new OA\Property(property: 'cross_border_countries', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
    new OA\Property(property: 'transfer_safeguards', type: 'string', nullable: true),
    new OA\Property(property: 'retention_period', type: 'string'),
    new OA\Property(property: 'security_measures', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
    new OA\Property(property: 'version', type: 'integer', example: 1),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacyProcessingActivityListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'Ordered by title', items: new OA\Items(ref: '#/components/schemas/PrivacyProcessingActivity')),
])]
#[OA\Schema(schema: 'PrivacyProcessingActivityResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacyProcessingActivity'),
])]
#[OA\Schema(schema: 'PrivacySubprocessorRequest', description: 'Subprocessor (third party processing personal data on behalf of the company). Validated inline by PrivacyComplianceController::storeSubprocessor.', required: ['name', 'service_type', 'country_code'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 190, example: 'Example Hosting AG'),
    new OA\Property(property: 'service_type', type: 'string', maxLength: 120, example: 'cloud hosting'),
    new OA\Property(property: 'country_code', description: 'ISO 3166-1 alpha-2 country of the processing; stored upper-case', type: 'string', minLength: 2, maxLength: 2, example: 'CH'),
    new OA\Property(property: 'safeguard_mechanism', description: 'Defaults to adequacy_decision for an adequate country, scc_fdpic otherwise', type: 'string', maxLength: 80, nullable: true),
    new OA\Property(property: 'dpa_signed_at', description: 'Date the data processing agreement was signed', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', description: 'Defaults to true', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'PrivacySubprocessor', description: 'Subprocessor with the result of the Swiss nLPD adequacy check of its country (Annex 1 DPOOP)', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'service_type', type: 'string'),
    new OA\Property(property: 'country_code', type: 'string'),
    new OA\Property(property: 'is_adequate_country_nlpd', type: 'boolean'),
    new OA\Property(property: 'safeguard_mechanism', type: 'string', nullable: true),
    new OA\Property(property: 'dpa_signed_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacySubprocessorListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'Ordered by name', items: new OA\Items(ref: '#/components/schemas/PrivacySubprocessor')),
])]
#[OA\Schema(schema: 'PrivacySubprocessorResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacySubprocessor'),
])]
#[OA\Schema(schema: 'PrivacyDataSubjectRequestRequest', description: 'Registration of a request of a data subject. Validated inline by PrivacyComplianceController::storeDataSubjectRequest.', required: ['request_type', 'subject_type', 'subject_id'], type: 'object', properties: [
    new OA\Property(property: 'request_type', type: 'string', enum: ['access', 'portability', 'erasure', 'restriction', 'rectification']),
    new OA\Property(property: 'regulation', type: 'string', enum: ['nLPD', 'GDPR', 'both'], nullable: true, description: 'Defaults to nLPD'),
    new OA\Property(property: 'subject_type', type: 'string', enum: ['contact', 'employee']),
    new OA\Property(property: 'subject_id', description: 'Identifier of the contact or employee the request is about (not checked against the tenant at registration)', type: 'string', format: 'uuid'),
    new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true, description: 'Free text: do not paste personal data of the subject here'),
])]
#[OA\Schema(schema: 'PrivacyDataSubjectRequest', description: 'Request of a data subject (access, portability, erasure, restriction, rectification) with its 30-day statutory deadline', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'request_type', type: 'string', enum: ['access', 'portability', 'erasure', 'restriction', 'rectification']),
    new OA\Property(property: 'regulation', type: 'string', enum: ['nLPD', 'GDPR', 'both']),
    new OA\Property(property: 'subject_type', type: 'string', enum: ['contact', 'employee']),
    new OA\Property(property: 'subject_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', description: 'received on registration; completed or partially_completed_legal_hold after the erasure is executed', type: 'string', example: 'received'),
    new OA\Property(property: 'deadline_at', description: 'Registration date plus 30 days', type: 'string', format: 'date-time'),
    new OA\Property(property: 'extended_until', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'extension_reason', type: 'string', nullable: true),
    new OA\Property(property: 'legal_holds', description: 'Statutory retention duties that prevented a full erasure', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/PrivacyLegalHold')),
    new OA\Property(property: 'erasure_summary', ref: '#/components/schemas/PrivacyErasureSummary', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'handled_by', description: 'User that registered or executed the request', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacyLegalHold', type: 'object', properties: [
    new OA\Property(property: 'statute', type: 'string', example: 'Swiss CO Art. 958f'),
    new OA\Property(property: 'retention_years', type: 'integer', example: 10),
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'PrivacyErasureSummary', type: 'object', properties: [
    new OA\Property(property: 'erased_fields', description: 'Names of the personal data fields that were wiped', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'processing_restricted', type: 'boolean'),
    new OA\Property(property: 'full_anonymization', description: 'False when a legal hold kept the name of the subject', type: 'boolean'),
    new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacyDataSubjectRequestListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'Newest first', items: new OA\Items(ref: '#/components/schemas/PrivacyDataSubjectRequest')),
])]
#[OA\Schema(schema: 'PrivacyDataSubjectRequestResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacyDataSubjectRequest'),
])]
#[OA\Schema(schema: 'PrivacySubjectDossier', description: 'Machine-readable portability dossier. Contains PERSONAL DATA of the subject (identity, contact details, tax and bank identifiers, payroll): handle and store it accordingly. Which blocks are present depends on subject_type: contact has commercial_records; employee has payroll_profile, employment_contracts, payslips and salary_certificates.', type: 'object', properties: [
    new OA\Property(property: 'schema', type: 'string', example: 'urn:fatturafacile:privacy:portability:1.0'),
    new OA\Property(property: 'regulations', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'exported_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'subject_type', type: 'string', enum: ['contact', 'employee']),
    new OA\Property(property: 'subject_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'personal_data', description: 'Personal data fields. Contact: company_name, first_name, last_name, email, phone, vat_number, fiscal_code, uid_number, iban, addresses. Employee: employee_number, first_name, last_name, personal_email, work_email, phone, birth_date, hire_date, termination_date, social_security_number, tax_code.', type: 'object', additionalProperties: true),
    new OA\Property(property: 'commercial_records', description: 'Contact only: invoices of the contact (id, invoice_number, date, due_date, total_net, total_tax, total_gross, currency, payment_status)', type: 'object', additionalProperties: true),
    new OA\Property(property: 'payroll_profile', description: 'Employee only: cantons, Swiss permit and withholding tax code; null without payroll profile', type: 'object', nullable: true, additionalProperties: true),
    new OA\Property(property: 'employment_contracts', description: 'Employee only', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
    new OA\Property(property: 'payslips', description: 'Employee only (id, year, month, gross_salary, employee_deductions, net_salary, payout_amount, created_at)', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
    new OA\Property(property: 'salary_certificates', description: 'Employee only (id, year, period_from, period_to, box8_gross, box11_net, box12_withholding_tax, generated_at)', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
])]
#[OA\Schema(schema: 'PrivacySubjectDossierResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacySubjectDossier'),
])]
#[OA\Schema(schema: 'PrivacyDataBreachRequest', description: 'Notification of a personal data breach. Validated inline by PrivacyComplianceController::storeBreach.', required: ['title', 'severity', 'data_categories'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190, example: 'Misdirected export file'),
    new OA\Property(property: 'severity', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'discovered_at', description: 'Defaults to now; the 72-hour notification deadline runs from here', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'affected_records_count', type: 'integer', minimum: 0, nullable: true),
    new OA\Property(property: 'data_categories', type: 'array', minItems: 1, items: new OA\Items(type: 'string', maxLength: 80), example: ['contact details']),
    new OA\Property(property: 'likely_consequences', type: 'string', maxLength: 2000, nullable: true),
    new OA\Property(property: 'mitigation_measures', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyDataBreach', description: 'Personal data breach register entry. Notification duties derive from the severity: authority for medium, high and critical; data subjects for high and critical.', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'severity', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'discovered_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'notification_deadline_at', description: 'discovered_at plus 72 hours (GDPR Art. 33, nLPD Art. 24)', type: 'string', format: 'date-time'),
    new OA\Property(property: 'fdpic_notified_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'gdpr_authority_notified_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'subjects_notified_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'affected_records_count', type: 'integer'),
    new OA\Property(property: 'data_categories', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'likely_consequences', type: 'string', nullable: true),
    new OA\Property(property: 'mitigation_measures', type: 'string', nullable: true),
    new OA\Property(property: 'requires_authority_notification', type: 'boolean'),
    new OA\Property(property: 'requires_subject_notification', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', example: 'investigating'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacyDataBreachListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'Most recently discovered first', items: new OA\Items(ref: '#/components/schemas/PrivacyDataBreach')),
])]
#[OA\Schema(schema: 'PrivacyDataBreachResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacyDataBreach'),
])]
#[OA\Schema(schema: 'PrivacySensitiveAccessLog', description: 'Log line of an access to sensitive personal data (Swiss DPOOP Art. 4). Contains the IP address of the user (personal data).', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', description: 'User that accessed the data', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'subject_type', type: 'string'),
    new OA\Property(property: 'subject_id', type: 'string'),
    new OA\Property(property: 'data_category', type: 'string', example: 'full_subject_dossier'),
    new OA\Property(property: 'action', type: 'string', example: 'portability_export'),
    new OA\Property(property: 'ip_address', description: 'Personal data', type: 'string', nullable: true),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacySensitiveAccessLogListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'The latest 200 accesses, newest first', items: new OA\Items(ref: '#/components/schemas/PrivacySensitiveAccessLog')),
])]
final class PrivacyComplianceSchemas {}
