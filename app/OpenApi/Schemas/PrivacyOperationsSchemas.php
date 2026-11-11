<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of the operational privacy endpoints: data subject request workflow, data map, privacy
 * settings, retention policies and breach operations. Responses may contain personal data (see the single endpoints).
 */
#[OA\Schema(schema: 'PrivacyVerifyIdentityRequest', required: ['method'], type: 'object', properties: [
    new OA\Property(property: 'method', description: 'How the identity was verified (for example id_document, known_account, callback)', type: 'string', maxLength: 60),
    new OA\Property(property: 'note', description: 'Do not paste the identity document data here', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyCompleteRequest', type: 'object', properties: [
    new OA\Property(property: 'response_summary', description: 'Response given to the data subject; required for rectification and for objections that are not about marketing', type: 'string', maxLength: 4000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyRejectRequest', required: ['reason'], type: 'object', properties: [
    new OA\Property(property: 'reason', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'PrivacyExtendRequest', required: ['days', 'reason'], type: 'object', properties: [
    new OA\Property(property: 'days', description: 'Days added to the deadline, at most 60 (art. 12(3) GDPR two months, to be confirmed)', type: 'integer', minimum: 1, maximum: 60),
    new OA\Property(property: 'reason', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'PrivacyDsrEvent', description: 'Step of the history of a data subject request', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'request_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'event', type: 'string', example: 'identity_verified'),
    new OA\Property(property: 'from_status', type: 'string', nullable: true),
    new OA\Property(property: 'to_status', type: 'string', nullable: true),
    new OA\Property(property: 'actor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'note', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PrivacyDataSubjectRequestDetailResponse', type: 'object', properties: [
    new OA\Property(property: 'data', allOf: [
        new OA\Schema(ref: '#/components/schemas/PrivacyDataSubjectRequest'),
        new OA\Schema(type: 'object', properties: [
            new OA\Property(property: 'events', type: 'array', items: new OA\Items(ref: '#/components/schemas/PrivacyDsrEvent')),
        ]),
    ]),
])]
#[OA\Schema(schema: 'PrivacyDataMapTable', type: 'object', properties: [
    new OA\Property(property: 'table', type: 'string', example: 'contacts'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'category', type: 'string', example: 'identity'),
    new OA\Property(property: 'models', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'personal_columns', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'undeclared_personal_columns', description: 'Columns that look personal and are not declared in the registry: the registry must be updated', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'subject_types', type: 'array', items: new OA\Items(type: 'string', enum: ['contact', 'employee', 'lead'])),
    new OA\Property(property: 'retention', type: 'string', enum: ['erasable', 'fiscal_record', 'statutory_employment', 'evidence']),
    new OA\Property(property: 'retention_note', type: 'string'),
    new OA\Property(property: 'retention_policy_available', type: 'boolean'),
    new OA\Property(property: 'rows', description: 'Rows held by the active company', type: 'integer'),
])]
#[OA\Schema(schema: 'PrivacyDataMapResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/PrivacyDataMapTable')),
        new OA\Property(property: 'linked_records', description: 'Tables that reference a subject without personal columns of their own', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'exemptions', description: 'Tables with personal-looking columns that hold no personal data of natural persons, with the reason', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'unregistered_tables', description: 'Only with scan=1: tables that look personal and are neither registered nor exempt', type: 'object', nullable: true),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
    ]),
])]
#[OA\Schema(schema: 'PrivacySettingsRequest', required: ['dsr_deadline_days'], type: 'object', properties: [
    new OA\Property(property: 'dsr_deadline_days', description: 'Days to answer a data subject request (1 to 90)', type: 'integer', minimum: 1, maximum: 90, example: 30),
])]
#[OA\Schema(schema: 'PrivacySettingsResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'dsr_deadline_days', type: 'integer'),
        new OA\Property(property: 'default_deadline_days', type: 'integer'),
        new OA\Property(property: 'to_be_confirmed', description: 'Always true: the statutory period must be confirmed by the data protection officer or counsel', type: 'boolean'),
        new OA\Property(property: 'note', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'PrivacyRetentionPolicyRequest', required: ['retention_days'], type: 'object', properties: [
    new OA\Property(property: 'retention_days', description: 'Rows older than this many days are anonymised', type: 'integer', minimum: 1, maximum: 36500),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    new OA\Property(property: 'legal_basis_note', description: 'Why this period was chosen', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyRetentionPolicy', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'entry_key', type: 'string', example: 'crm_leads'),
    new OA\Property(property: 'retention_days', type: 'integer'),
    new OA\Property(property: 'action', type: 'string', example: 'anonymise'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'legal_basis_note', type: 'string', nullable: true),
    new OA\Property(property: 'last_run_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'PrivacyRetentionPolicyResponse', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/PrivacyRetentionPolicy'),
])]
#[OA\Schema(schema: 'PrivacyRetentionPolicyListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PrivacyRetentionPolicy')),
    new OA\Property(property: 'available_tables', description: 'Tables of the registry that can have a policy', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'PrivacyRetentionRunRequest', type: 'object', properties: [
    new OA\Property(property: 'dry_run', description: 'Defaults to true: rows are only counted', type: 'boolean', nullable: true),
    new OA\Property(property: 'entry_key', description: 'Run only the policy of this table', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PrivacyRetentionRun', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'policy_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'entry_key', type: 'string'),
    new OA\Property(property: 'dry_run', type: 'boolean'),
    new OA\Property(property: 'matched_count', type: 'integer'),
    new OA\Property(property: 'processed_count', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['completed', 'skipped', 'failed']),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'details', type: 'object', nullable: true),
])]
#[OA\Schema(schema: 'PrivacyRetentionRunListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PrivacyRetentionRun')),
])]
#[OA\Schema(schema: 'PrivacyBreachContainRequest', type: 'object', properties: [
    new OA\Property(property: 'mitigation_measures', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyBreachNotifyAuthorityRequest', required: ['regulation'], type: 'object', properties: [
    new OA\Property(property: 'regulation', description: 'nLPD records the notification to the Swiss FDPIC, GDPR to the competent authority', type: 'string', enum: ['nLPD', 'GDPR']),
    new OA\Property(property: 'notified_at', description: 'Defaults to now; cannot precede the discovery', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'delay_reason', description: 'Required when notified after the 72 hour deadline', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'PrivacyBreachNotifySubjectsRequest', type: 'object', properties: [
    new OA\Property(property: 'notified_at', type: 'string', format: 'date-time', nullable: true),
])]
final class PrivacyOperationsSchemas {}
